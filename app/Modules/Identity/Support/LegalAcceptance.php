<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M0-10: timestamped acceptance of the published legal documents, with the accepted version.
 */
final class LegalAcceptance
{
    /**
     * Latest published version of each requested document type, in the best available locale.
     *
     * @param  list<string>  $types
     * @return Collection<int, object>
     */
    public static function current(array $types, string $locale = 'fr'): Collection
    {
        return collect($types)->map(fn (string $type) => DB::table('legal_documents')
            ->where('document_type', $type)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByRaw('locale = ? desc', [$locale])->orderByDesc('published_at')->orderByDesc('id')
            ->first(['id', 'document_type', 'version', 'locale', 'title', 'content_html', 'published_at']))
            ->filter()->values();
    }

    /**
     * @param  list<string>  $types
     */
    public static function record(User $user, ?int $companyId, array $types, string $context, Request $request): void
    {
        $now = now();

        DB::table('legal_acceptances')->insert(self::current($types, $user->locale)->map(fn (object $document) => [
            'user_id' => $user->id,
            'legal_document_id' => $document->id,
            'company_id' => $companyId,
            'context' => $context,
            'accepted_at' => $now,
            'ip_address' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            'created_at' => $now,
        ])->all());
    }
}
