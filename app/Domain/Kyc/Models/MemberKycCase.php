<?php

namespace App\Domain\Kyc\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberKycCase extends Model
{
    use HasUlids;

    protected $table = 'member_kyc_cases';

    protected $fillable = [
        'user_id', 'status', 'verification_mode', 'identity_payload',
        'document_front_path', 'document_back_path', 'company_document_path',
        'privacy_notice_accepted', 'submitted_at', 'reviewed_by', 'review_reason',
        'reviewed_at', 'provider_reference', 'provider_reviewed_at',
    ];

    protected $hidden = ['identity_payload', 'document_front_path', 'document_back_path', 'company_document_path'];

    protected function casts(): array
    {
        return [
            'identity_payload' => 'encrypted:array',
            'privacy_notice_accepted' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'provider_reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
