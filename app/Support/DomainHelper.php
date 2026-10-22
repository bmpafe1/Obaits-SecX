<?php
namespace App\Support;
 
class DomainHelper
{
    // "https://www.AAA.com/path" -> "aaa.com"
    public static function fromWebsite(string $website): string
    {
        $host = preg_replace('#^https?://#', '', strtolower(trim($website)));
        $host = preg_replace('#^www\.#', '', $host);
        return explode('/', $host)[0];
    }
 
    // "b@aaa.com" -> "aaa.com"
    public static function fromEmail(string $email): string
    {
        return strtolower(substr(strrchr($email, '@') ?: '', 1));
    }
 
    // main label for the slug: "aaa.com" -> "aaa"
    public static function slug(string $domain): string
    {
        return explode('.', $domain)[0];
    }
}
