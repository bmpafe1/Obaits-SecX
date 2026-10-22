<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supported = ['en', 'fr', 'es', 'de', 'nl'];

        // 1. A language the user explicitly chose (stored in session) always wins.
        $locale = Session::get('locale');

        // 2. Otherwise, fall back to the browser's preferred language.
        if (!$locale) {
            $locale = substr($request->getPreferredLanguage($supported) ?? 'en', 0, 2);
        }

        // 3. Never trust it blindly — only ever set a language we actually support.
        if (!in_array($locale, $supported, true)) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
