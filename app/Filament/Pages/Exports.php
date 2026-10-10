<?php

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Backoffice\Support\Format;
use App\Domain\Reporting\CsvWriter;
use App\Domain\Reporting\Jobs\GenerateSnapshotJob;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\ReportingQueries;
use App\Filament\Support\StepUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property-read Action $exportMembersAction
 * @property-read Action $exportPaymentsAction
 * @property-read Action $exportSubscriptionsAction
 * @property-read Action $exportMerchantPaymentsAction
 */
class Exports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Données';

    protected static ?string $navigationLabel = 'Exports';

    protected static ?string $title = 'Exports';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.exports';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') === true;
    }

    public function getSubheading(): ?string
    {
        return 'Fichiers CSV (séparateur « ; », UTF-8) à ouvrir directement dans Excel. Chaque export demande une confirmation par code e-mail et est inscrit au journal d\'audit.';
    }

    /**
     * @return array<string, array{title: string, description: string, action: Action}>
     */
    public function exports(): array
    {
        return [
            'members' => ['title' => 'Liste des membres', 'description' => 'Identité, Pernum, statut du compte et de l\'adhésion, familles de packs, solde YEM.', 'action' => $this->exportMembersAction],
            'payments' => ['title' => 'Paiements confirmés', 'description' => 'Paiements de la période avec membre, objet, montant, frais et moyen de paiement.', 'action' => $this->exportPaymentsAction],
            'subscriptions' => ['title' => 'Souscriptions', 'description' => 'Packs actifs avec dates de début et de fin, quotas et soldes.', 'action' => $this->exportSubscriptionsAction],
            'merchant_payments' => ['title' => 'Paiements marchands', 'description' => 'Paiements de YEM aux marchands créés sur la période, quel que soit leur statut.', 'action' => $this->exportMerchantPaymentsAction],
        ];
    }

    public function exportMembersAction(): Action
    {
        return $this->exportAction('exportMembers', 'members', false);
    }

    public function exportPaymentsAction(): Action
    {
        return $this->exportAction('exportPayments', 'payments', true);
    }

    public function exportSubscriptionsAction(): Action
    {
        return $this->exportAction('exportSubscriptions', 'subscriptions', false);
    }

    public function exportMerchantPaymentsAction(): Action
    {
        return $this->exportAction('exportMerchantPayments', 'merchant_payments', true);
    }

    private function exportAction(string $name, string $type, bool $withPeriod): Action
    {
        return Action::make($name)
            ->label('Télécharger')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (): bool => auth()->user()?->can('reports.export') === true)
            ->modalHeading('Exporter en CSV')
            ->modalSubmitActionLabel('Télécharger')
            ->schema([
                ...($withPeriod ? [
                    DatePicker::make('from')->label('Du')->native(false)->required()->default(now()->subDays(30)),
                    DatePicker::make('to')->label('Au')->native(false)->required()->default(now())->afterOrEqual('from'),
                ] : []),
                ...StepUp::fields(),
            ])
            ->mountUsing(fn () => StepUp::issue())
            ->action(function (Action $action, array $data) use ($type, $withPeriod): StreamedResponse {
                StepUp::confirm($action, $data);

                $queries = app(ReportingQueries::class);
                $from = $withPeriod ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now();
                $to = $withPeriod ? CarbonImmutable::parse($data['to'])->addDay()->startOfDay() : CarbonImmutable::now();

                $rows = match ($type) {
                    'members' => $queries->membersRows(),
                    'payments' => $queries->paymentsRows($from, $to),
                    'subscriptions' => $queries->subscriptionsRows(),
                    default => $queries->merchantPaymentsRows($from, $to),
                };

                app(AuditLogger::class)->record('reports.exported', metadata: array_filter([
                    'type' => $type,
                    'from' => $withPeriod ? $from->toIso8601ZuluString() : null,
                    'to' => $withPeriod ? $to->toIso8601ZuluString() : null,
                ]));

                return response()->streamDownload(function () use ($rows): void {
                    $output = fopen('php://output', 'wb');

                    if ($output !== false) {
                        CsvWriter::write($output, $rows);
                        fclose($output);
                    }
                }, sprintf('cect-%s-%s.csv', $type, now()->format('Ymd-His')), ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ExportSnapshot::query()->orderByDesc('version'))
            ->heading('Snapshots consolidés')
            ->description('Sauvegardes complètes des données, contrôlables hors ligne grâce à leur empreinte SHA-256.')
            ->headerActions([$this->generateSnapshotAction()])
            ->columns([
                TextColumn::make('version')->label('Version')->weight('bold')->formatStateUsing(fn (int $state): string => 'Version '.$state),
                TextColumn::make('trigger')->label('Déclenché par')->formatStateUsing(fn (string $state): string => $state === 'manual' ? 'Manuel' : ($state === 'scheduled' ? 'Planifié' : $state))->visibleFrom('lg'),
                TextColumn::make('generated_at')->label('Généré le')->state(fn (ExportSnapshot $record): string => $record->generated_at === null ? '—' : Format::dateTime($record->generated_at)),
                TextColumn::make('size_bytes')->label('Taille')->alignEnd()->state(fn (ExportSnapshot $record): string => $record->size_bytes === null ? '—' : number_format($record->size_bytes / 1024, 0, ',', ' ').' Ko')->visibleFrom('md'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ExportSnapshot::READY => 'Prêt',
                        ExportSnapshot::FAILED => 'Échec',
                        ExportSnapshot::PRUNED => 'Supprimé',
                        default => 'En cours',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        ExportSnapshot::READY => 'success',
                        ExportSnapshot::FAILED => 'danger',
                        ExportSnapshot::PRUNED => 'gray',
                        default => 'warning',
                    }),
            ])
            ->recordActions([$this->downloadSnapshotAction()])
            ->paginated([10, 25])
            ->emptyStateHeading('Aucun snapshot')
            ->emptyStateDescription('Générez-en un pour obtenir une sauvegarde consolidée.');
    }

    private function generateSnapshotAction(): Action
    {
        return Action::make('generate')
            ->label('Générer un snapshot')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => auth()->user()?->can('reports.export') === true)
            ->requiresConfirmation()
            ->modalDescription('Le snapshot est préparé en arrière-plan ; il apparaît dans la liste quand il est prêt.')
            ->action(function (): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 401);

                GenerateSnapshotJob::dispatch($user);
                Notification::make()->title('Génération lancée.')->success()->send();
            });
    }

    private function downloadSnapshotAction(): Action
    {
        return Action::make('download')
            ->label('Télécharger')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Télécharger le snapshot')
            ->modalSubmitActionLabel('Télécharger')
            ->visible(fn (ExportSnapshot $record): bool => $record->status === ExportSnapshot::READY && auth()->user()?->can('reports.export') === true)
            ->schema(StepUp::fields())
            ->mountUsing(fn () => StepUp::issue())
            ->action(function (Action $action, ExportSnapshot $record, array $data) {
                StepUp::confirm($action, $data);

                if ($record->path === null || ! Storage::disk('local')->exists($record->path)) {
                    Notification::make()->title('Le fichier de ce snapshot est introuvable.')->danger()->send();
                    $action->halt();

                    return null;
                }

                app(AuditLogger::class)->record('reports.snapshot_downloaded', $record, metadata: ['version' => $record->version, 'sha256' => $record->sha256]);

                return Storage::disk('local')->download($record->path, basename($record->path), [
                    'Content-Type' => 'application/zip',
                    'X-Content-SHA256' => (string) $record->sha256,
                ]);
            });
    }
}
