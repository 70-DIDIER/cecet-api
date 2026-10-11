<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Membership\Models\Membership;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Reporting\PdfSummaryWriter;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class MemberDocumentController
{
    private const TYPES = [
        'membership-receipt',
        'membership-card',
        'pack-statement',
        'activity-statement',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $membership = Membership::query()->where('user_id', $user->id)->first();
        $active = $membership?->activated_at !== null;

        $documents = collect(self::TYPES)
            ->filter(fn (string $type): bool => ! in_array($type, ['membership-receipt', 'membership-card'], true) || $active)
            ->map(fn (string $type): array => $this->descriptor($type))
            ->values()
            ->all();

        return response()->json(['data' => $documents]);
    }

    public function download(Request $request, string $type, AuditLogger $auditLogger): Response
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $user = $this->user($request);
        $membership = Membership::query()->where('user_id', $user->id)->first();

        if (in_array($type, ['membership-receipt', 'membership-card'], true)) {
            abort_unless($membership?->activated_at !== null, 404);
        }

        [$title, $rows] = match ($type) {
            'membership-receipt' => $this->membershipReceipt($user, $membership),
            'membership-card' => $this->membershipCard($user, $membership),
            'pack-statement' => $this->packStatement($user),
            'activity-statement' => $this->activityStatement($user),
        };

        $auditLogger->record('member_documents.downloaded', metadata: [
            'document_type' => $type,
            'user_id' => $user->id,
        ], actor: $user);

        $content = PdfSummaryWriter::make($title, $rows);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cect-'.$type.'-'.now()->format('Ymd').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function descriptor(string $type): array
    {
        return match ($type) {
            'membership-receipt' => ['type' => $type, 'title' => "Reçu d'adhésion", 'description' => "Montant et date d'activation de votre adhésion.", 'format' => 'PDF'],
            'membership-card' => ['type' => $type, 'title' => "Fiche d'adhésion", 'description' => 'Identité CECT, Pernum et statut du membre.', 'format' => 'PDF'],
            'pack-statement' => ['type' => $type, 'title' => 'Relevé de packs', 'description' => 'Packs détenus, versions achetées, statuts et périodes.', 'format' => 'PDF'],
            'activity-statement' => ['type' => $type, 'title' => "Relevé d'activité", 'description' => 'Dernières opérations YEM suivies par la plateforme.', 'format' => 'PDF'],
        };
    }

    private function membershipReceipt(User $user, Membership $membership): array
    {
        return [
            "Reçu d'adhésion CECT",
            [
                ['Identifiant membre', $user->member_number],
                ['Nom / raison sociale', $user->organization->legal_name ?? $user->full_name],
                ['Montant adhésion', $membership->fee_amount.' '.$membership->fee_currency],
                ['Date paiement', $membership->paid_at?->toIso8601String()],
                ['Date activation', $membership->activated_at?->toIso8601String()],
                ['Mode activation', $membership->activation_mode?->value],
                ['Statut', $membership->status->value],
            ],
        ];
    }

    private function membershipCard(User $user, Membership $membership): array
    {
        return [
            "Fiche d'adhésion CECT",
            [
                ['Identifiant membre', $user->member_number],
                ['Type de personne', $user->person_type?->value],
                ['Nom / raison sociale', $user->organization->legal_name ?? $user->full_name],
                ['Email', $user->email],
                ['Téléphone', $user->phone],
                ['Pernum', $user->pernumIdentities()->where('status', '!=', 'revoked')->latest()->value('pernum')],
                ['Statut', $membership->status->value],
                ['Membre depuis', $membership->activated_at?->toIso8601String()],
            ],
        ];
    }

    private function packStatement(User $user): array
    {
        $rows = [['Membre', $user->member_number]];

        UserPack::query()
            ->where('user_id', $user->id)
            ->with(['plan', 'version'])
            ->orderByDesc('created_at')
            ->get()
            ->each(function (UserPack $pack) use (&$rows): void {
                $prefix = $pack->plan->code.' v'.$pack->version->version;
                $rows[] = [$prefix.' / Famille', $pack->family->value];
                $rows[] = [$prefix.' / Statut', $pack->status->value];
                $rows[] = [$prefix.' / Prix', $pack->price_xof.' XOF'];
                $rows[] = [$prefix.' / Activation', $pack->activated_at?->toIso8601String()];
                $rows[] = [$prefix.' / Expiration', $pack->expires_at?->toIso8601String()];
            });

        return ['Relevé de packs CECT', $rows];
    }

    private function activityStatement(User $user): array
    {
        $rows = [['Membre', $user->member_number]];

        ExchangeRequest::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->each(function (ExchangeRequest $exchange) use (&$rows): void {
                $rows[] = [
                    'Échange '.$exchange->reference,
                    $exchange->kind->value.' · '.$exchange->status->value.' · '.$exchange->quantity_requested.' YEM · '.$exchange->created_at->toIso8601String(),
                ];
            });

        MerchantPayment::query()
            ->where('payer_id', $user->id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->each(function (MerchantPayment $payment) use (&$rows): void {
                $rows[] = [
                    'Paiement '.$payment->reference,
                    $payment->status->value.' · '.$payment->quantity.' YEM · '.$payment->created_at->toIso8601String(),
                ];
            });

        return ["Relevé d'activité CECT", $rows];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isMember(), 403);

        return $user;
    }
}
