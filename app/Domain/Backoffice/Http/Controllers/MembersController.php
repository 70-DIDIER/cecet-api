<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Access\AccountManager;
use App\Domain\Access\AccountSearch;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The member directory: search, filters, and the full file of one member with the means to suspend or reactivate.
 */
class MembersController
{
    public function __construct(
        private readonly AccountSearch $search,
        private readonly AccountManager $accounts,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'person_type' => ['nullable', Rule::enum(PersonType::class)],
            'family' => ['nullable', 'string', 'in:VENDEUR,CONSOMMATEUR,PERSONNEL,MARCHAND'],
        ]);

        return view('backoffice.members.index', [
            'members' => $this->search->builder(UserKind::Member, $filters)->paginate(25)->withQueryString(),
            'filters' => $filters,
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function show(User $user): View
    {
        abort_unless($user->kind === UserKind::Member, 404);

        $user->load(['organization', 'pernumIdentities']);
        $intentIds = PaymentIntent::query()->where('user_id', $user->id)->select('id');
        $packs = UserPack::query()->where('user_id', $user->id)->with(['plan', 'version', 'account'])->orderByDesc('created_at')->get();
        $merchantAccounts = MerchantAccount::query()->whereIn('user_pack_id', $packs->pluck('id'))->get()->keyBy('user_pack_id');

        return view('backoffice.members.show', [
            'member' => $user,
            'membership' => Membership::query()->where('user_id', $user->id)->first(),
            'packs' => $packs,
            'merchantAccounts' => $merchantAccounts,
            'payments' => PaymentAttempt::query()->whereIn('payment_intent_id', $intentIds)->with('intent')->orderByDesc('created_at')->limit(10)->get(),
            'exchanges' => ExchangeRequest::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(10)->get(),
            'merchantPayments' => MerchantPayment::query()->where(fn ($query) => $query->where('payer_id', $user->id)->orWhere('merchant_id', $user->id))->with(['payer', 'merchant'])->orderByDesc('created_at')->limit(10)->get(),
            'events' => AuditEvent::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->orderByDesc('position')->limit(10)->get(),
        ]);
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->kind === UserKind::Member, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->run(fn () => $this->accounts->suspend($this->actor($request), $user, $validated['reason']), $user, 'Compte suspendu : ses sessions sont fermées.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->kind === UserKind::Member, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->run(fn () => $this->accounts->reactivate($this->actor($request), $user, $validated['reason']), $user, 'Compte réactivé.');
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, User $user, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ApiException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.members.show', $user)->with('status', $success);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
