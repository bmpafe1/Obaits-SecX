<?php
namespace App\Clients;
 
use Illuminate\Support\Facades\Http;
 
class CisoClient
{
    private function baseUrl(): string
    {
        return rtrim(config('services.ciso.base_url'), '/');
    }
 
    private function auth(): array
    {
        return ['Authorization' => 'Token ' . config('services.ciso.api_token')];
    }
 
    // Returns the folder (domain) id, existing or newly created.
     public function ensureDomain(string $name): string
    {
        $list = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->get($this->baseUrl() . '/api/folders/', ['name' => $name]);
 
        if ($list->status() === 401) {
            throw new \RuntimeException('CISO auth failed (401) — token invalid or expired');
        }
 
        foreach (($list->json('results') ?? []) as $f) {
            if (($f['name'] ?? null) === $name) return $f['id'];
        }
 
        $created = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->post(
            $this->baseUrl() . '/api/folders/',
            ['name' => $name, 'content_type' => 'DOMAIN', 'description' => 'tenant ' . $name]
        );
 
        if ($created->status() === 401) {
            throw new \RuntimeException('CISO auth failed (401) — token invalid or expired');
        }
        if (!$created->successful() || empty($created->json('id'))) {
            throw new \RuntimeException('CISO domain create failed: HTTP ' . $created->status() . ' ' . $created->body());
        }
 
        return $created->json('id');
    }

 
    public function deleteDomain(string $folderId): void
    {
        $resp = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->delete(
            $this->baseUrl() . "/api/folders/$folderId/"
        );
        // 404 = already gone, which is the goal of a delete. Anything else that
        // is not 2xx means the domain is still there — say so. Added 2026-07-22
        // (unchecked teardown calls logged success while deleting nothing).
        if (!$resp->successful() && $resp->status() !== 404) {
            throw new \RuntimeException(
                'CISO domain delete failed: HTTP ' . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
            );
        }
    }

    // List evidences for a tenant's CISO folder (domain).
    public function evidencesForFolder(string $folderId): array
    {
        $res = \Illuminate\Support\Facades\Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(30)
            ->get($this->baseUrl() . '/api/evidences/', ['folder' => $folderId]);
        $data = $res->json();
        return $data['results'] ?? [];
    }

    // Upload an evidence file into a tenant's CISO folder.
    public function uploadEvidence(string $folderId, string $name, string $filePath, string $originalName): array
    {
        $res = \Illuminate\Support\Facades\Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(60)
            ->attach('attachment', file_get_contents($filePath), $originalName)
            ->post($this->baseUrl() . '/api/evidences/', [
                'name' => $name,
                'folder' => $folderId,
            ]);
        return $res->json() ?? [];
    }

    // Fetch one evidence (for details / attachment URL).
    public function evidence(string $id): array
    {
        $res = \Illuminate\Support\Facades\Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(30)
            ->get($this->baseUrl() . '/api/evidences/' . $id . '/');
        return $res->json() ?? [];
    }

    // All frameworks currently loaded in CISO Assistant.
    public function frameworks(): array
    {
        $res = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(60)
            ->get($this->baseUrl() . '/api/frameworks/');

        return $res->json('results') ?? [];
    }

    // The control tree for a framework. Only assessable nodes take evidence.
    public function requirements(string $frameworkId): array
    {
        $res = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(90)
            ->get($this->baseUrl() . '/api/requirement-nodes/', [
                'framework' => $frameworkId,
                'page_size' => 500,
            ]);

        $all = $res->json('results') ?? [];

        // Structural headings carry no evidence — keep only the real requirements.
        return array_values(array_filter($all, fn ($n) => ($n['assessable'] ?? false) === true));
    }

    /**
     * Does this CISO domain (folder) still exist?
     * true = exists, false = confirmed gone (404), null = CISO did not answer.
     */
    public function folderExists(string $folderId): ?bool
    {
        try {
            $res = Http::withHeaders($this->auth())
                ->withoutVerifying()->timeout(20)
                ->get($this->baseUrl() . "/api/folders/{$folderId}/");

            if ($res->status() === 404) return false;    // definitively gone
            if (!$res->successful()) return null;        // we could not tell
            return true;
        } catch (\Throwable $e) {
            return null;
        }
    }

     /**
     * The catalogue of frameworks AVAILABLE to import (268 of them), most not yet loaded.
     * Each has id, urn, name, description, ref_id, locale, provider.
     */
    public function storedLibraries(): array
    {
        $res = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(60)
            ->get($this->baseUrl() . '/api/stored-libraries/', ['page_size' => 500]);
 
        return $res->json('results') ?? [];
    }
 
    /**
     * Import a stored library into CISO, turning it into a usable framework.
     * Idempotent from our side: if CISO says it is already loaded, we treat that as success.
     * Returns true on success (or already-present), false on a real failure.
     */
    public function importLibrary(string $storedLibraryId): bool
    {
        $res = Http::withHeaders($this->auth())
            ->withoutVerifying()
            ->timeout(120)   // importing a large framework is not instant
            ->post($this->baseUrl() . "/api/stored-libraries/{$storedLibraryId}/import/");
 
        if ($res->successful()) {
            return true;
        }
 
        // CISO returns an error if the library is already imported — that is fine for us.
        $body = strtolower($res->body());
        if (str_contains($body, 'already') || str_contains($body, 'exists')) {
            return true;
        }
 
        return false;
    }

}