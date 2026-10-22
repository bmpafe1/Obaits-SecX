<?php

namespace App\Support;

/**
 * ISO 3166-1 alpha-2 country codes -- the complete list, 249 entries.
 *
 * ONE list, used everywhere the platform needs a country: tenant profiles,
 * marketplace regions, provider coverage. Country was previously free text in
 * some places and absent in others, which meant nothing could be filtered or
 * compared -- a provider covering "Cameroon" and a tenant in "CM" would never
 * have matched.
 *
 * AFRICA FIRST, then everywhere else, each alphabetically. The platform's
 * market is Africa and a Cameroonian signing up should not scroll past
 * Afghanistan to reach their own country. This is a deliberate usability
 * decision, not an ordering accident.
 *
 * COMPLETE rather than curated: an earlier draft listed only the markets we
 * expect, which would have turned away any prospect from a country nobody
 * thought to include. A signup form is the wrong place to be opinionated about
 * where a customer may be from.
 *
 * Names are in English and lightly shortened where the formal ISO name is
 * unwieldy ("Congo (DRC)" rather than "Congo, The Democratic Republic of the").
 * Translating 249 names per locale is not worth the maintenance.
 *
 * Generated from pycountry (ISO 3166-1) rather than typed by hand.
 */
class Countries
{
    /** The platform's own market, listed first. */
    private const AFRICA = [
        'DZ' => 'Algeria',
        'AO' => 'Angola',
        'BJ' => 'Benin',
        'BW' => 'Botswana',
        'BF' => 'Burkina Faso',
        'BI' => 'Burundi',
        'CV' => 'Cabo Verde',
        'CM' => 'Cameroon',
        'CF' => 'Central African Republic',
        'TD' => 'Chad',
        'KM' => 'Comoros',
        'CD' => 'Congo (DRC)',
        'CG' => 'Congo (Republic)',
        'CI' => 'Côte d\'Ivoire',
        'DJ' => 'Djibouti',
        'EG' => 'Egypt',
        'GQ' => 'Equatorial Guinea',
        'ER' => 'Eritrea',
        'SZ' => 'Eswatini',
        'ET' => 'Ethiopia',
        'GA' => 'Gabon',
        'GM' => 'Gambia',
        'GH' => 'Ghana',
        'GN' => 'Guinea',
        'GW' => 'Guinea-Bissau',
        'KE' => 'Kenya',
        'LS' => 'Lesotho',
        'LR' => 'Liberia',
        'LY' => 'Libya',
        'MG' => 'Madagascar',
        'MW' => 'Malawi',
        'ML' => 'Mali',
        'MR' => 'Mauritania',
        'MU' => 'Mauritius',
        'YT' => 'Mayotte',
        'MA' => 'Morocco',
        'MZ' => 'Mozambique',
        'NA' => 'Namibia',
        'NE' => 'Niger',
        'NG' => 'Nigeria',
        'RW' => 'Rwanda',
        'RE' => 'Réunion',
        'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
        'ST' => 'Sao Tome and Principe',
        'SN' => 'Senegal',
        'SC' => 'Seychelles',
        'SL' => 'Sierra Leone',
        'SO' => 'Somalia',
        'ZA' => 'South Africa',
        'SS' => 'South Sudan',
        'SD' => 'Sudan',
        'TZ' => 'Tanzania',
        'TG' => 'Togo',
        'TN' => 'Tunisia',
        'UG' => 'Uganda',
        'EH' => 'Western Sahara',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ];

    private const REST = [
        'AF' => 'Afghanistan',
        'AL' => 'Albania',
        'AS' => 'American Samoa',
        'AD' => 'Andorra',
        'AI' => 'Anguilla',
        'AQ' => 'Antarctica',
        'AG' => 'Antigua and Barbuda',
        'AR' => 'Argentina',
        'AM' => 'Armenia',
        'AW' => 'Aruba',
        'AU' => 'Australia',
        'AT' => 'Austria',
        'AZ' => 'Azerbaijan',
        'BS' => 'Bahamas',
        'BH' => 'Bahrain',
        'BD' => 'Bangladesh',
        'BB' => 'Barbados',
        'BY' => 'Belarus',
        'BE' => 'Belgium',
        'BZ' => 'Belize',
        'BM' => 'Bermuda',
        'BT' => 'Bhutan',
        'BO' => 'Bolivia',
        'BQ' => 'Bonaire, Sint Eustatius and Saba',
        'BA' => 'Bosnia and Herzegovina',
        'BV' => 'Bouvet Island',
        'BR' => 'Brazil',
        'IO' => 'British Indian Ocean Territory',
        'VG' => 'British Virgin Islands',
        'BN' => 'Brunei',
        'BG' => 'Bulgaria',
        'KH' => 'Cambodia',
        'CA' => 'Canada',
        'KY' => 'Cayman Islands',
        'CL' => 'Chile',
        'CN' => 'China',
        'CX' => 'Christmas Island',
        'CC' => 'Cocos (Keeling) Islands',
        'CO' => 'Colombia',
        'CK' => 'Cook Islands',
        'CR' => 'Costa Rica',
        'HR' => 'Croatia',
        'CU' => 'Cuba',
        'CW' => 'Curaçao',
        'CY' => 'Cyprus',
        'CZ' => 'Czechia',
        'DK' => 'Denmark',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'EC' => 'Ecuador',
        'SV' => 'El Salvador',
        'EE' => 'Estonia',
        'FK' => 'Falkland Islands (Malvinas)',
        'FO' => 'Faroe Islands',
        'FJ' => 'Fiji',
        'FI' => 'Finland',
        'FR' => 'France',
        'GF' => 'French Guiana',
        'PF' => 'French Polynesia',
        'TF' => 'French Southern Territories',
        'GE' => 'Georgia',
        'DE' => 'Germany',
        'GI' => 'Gibraltar',
        'GR' => 'Greece',
        'GL' => 'Greenland',
        'GD' => 'Grenada',
        'GP' => 'Guadeloupe',
        'GU' => 'Guam',
        'GT' => 'Guatemala',
        'GG' => 'Guernsey',
        'GY' => 'Guyana',
        'HT' => 'Haiti',
        'HM' => 'Heard Island and McDonald Islands',
        'HN' => 'Honduras',
        'HK' => 'Hong Kong',
        'HU' => 'Hungary',
        'IS' => 'Iceland',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IR' => 'Iran',
        'IQ' => 'Iraq',
        'IE' => 'Ireland',
        'IM' => 'Isle of Man',
        'IL' => 'Israel',
        'IT' => 'Italy',
        'JM' => 'Jamaica',
        'JP' => 'Japan',
        'JE' => 'Jersey',
        'JO' => 'Jordan',
        'KZ' => 'Kazakhstan',
        'KI' => 'Kiribati',
        'KW' => 'Kuwait',
        'KG' => 'Kyrgyzstan',
        'LA' => 'Laos',
        'LV' => 'Latvia',
        'LB' => 'Lebanon',
        'LI' => 'Liechtenstein',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MO' => 'Macao',
        'MY' => 'Malaysia',
        'MV' => 'Maldives',
        'MT' => 'Malta',
        'MH' => 'Marshall Islands',
        'MQ' => 'Martinique',
        'MX' => 'Mexico',
        'FM' => 'Micronesia',
        'MD' => 'Moldova',
        'MC' => 'Monaco',
        'MN' => 'Mongolia',
        'ME' => 'Montenegro',
        'MS' => 'Montserrat',
        'MM' => 'Myanmar',
        'NR' => 'Nauru',
        'NP' => 'Nepal',
        'NL' => 'Netherlands',
        'NC' => 'New Caledonia',
        'NZ' => 'New Zealand',
        'NI' => 'Nicaragua',
        'NU' => 'Niue',
        'NF' => 'Norfolk Island',
        'KP' => 'North Korea',
        'MK' => 'North Macedonia',
        'MP' => 'Northern Mariana Islands',
        'NO' => 'Norway',
        'OM' => 'Oman',
        'PK' => 'Pakistan',
        'PW' => 'Palau',
        'PS' => 'Palestine',
        'PA' => 'Panama',
        'PG' => 'Papua New Guinea',
        'PY' => 'Paraguay',
        'PE' => 'Peru',
        'PH' => 'Philippines',
        'PN' => 'Pitcairn',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'PR' => 'Puerto Rico',
        'QA' => 'Qatar',
        'RO' => 'Romania',
        'RU' => 'Russia',
        'BL' => 'Saint Barthélemy',
        'KN' => 'Saint Kitts and Nevis',
        'LC' => 'Saint Lucia',
        'MF' => 'Saint Martin (French part)',
        'PM' => 'Saint Pierre and Miquelon',
        'VC' => 'Saint Vincent and the Grenadines',
        'WS' => 'Samoa',
        'SM' => 'San Marino',
        'SA' => 'Saudi Arabia',
        'RS' => 'Serbia',
        'SG' => 'Singapore',
        'SX' => 'Sint Maarten (Dutch part)',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'SB' => 'Solomon Islands',
        'GS' => 'South Georgia and the South Sandwich Islands',
        'KR' => 'South Korea',
        'ES' => 'Spain',
        'LK' => 'Sri Lanka',
        'SR' => 'Suriname',
        'SJ' => 'Svalbard and Jan Mayen',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
        'SY' => 'Syria',
        'TW' => 'Taiwan',
        'TJ' => 'Tajikistan',
        'TH' => 'Thailand',
        'TL' => 'Timor-Leste',
        'TK' => 'Tokelau',
        'TO' => 'Tonga',
        'TT' => 'Trinidad and Tobago',
        'TM' => 'Turkmenistan',
        'TC' => 'Turks and Caicos Islands',
        'TV' => 'Tuvalu',
        'TR' => 'Türkiye',
        'VI' => 'US Virgin Islands',
        'UA' => 'Ukraine',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'UM' => 'United States Minor Outlying Islands',
        'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
        'VU' => 'Vanuatu',
        'VA' => 'Vatican City',
        'VE' => 'Venezuela',
        'VN' => 'Vietnam',
        'WF' => 'Wallis and Futuna',
        'YE' => 'Yemen',
        'AX' => 'Åland Islands',
    ];

    /** Africa first, then the rest. Both alphabetical within their group. */
    public static function list(): array
    {
        return self::AFRICA + self::REST;
    }

    /**
     * Languages a training is likely to be useful in, for a tenant in this
     * country.
     *
     * USED FOR DEFAULTING A FILTER, not for asserting facts about a country.
     * The Trainings catalogue shows courses in the tenant's own preferred
     * language plus English, widened by the languages of their country -- so a
     * Cameroonian tenant sees French and English, a Belgian sees French, Dutch,
     * German and English.
     *
     * DELIBERATELY PARTIAL. Only genuinely multilingual countries and the
     * markets this platform actually serves are listed; everywhere else falls
     * back to English, which is the safe default for a filter rather than a
     * claim about the country. Getting this exhaustively right for 249
     * countries is a maintenance burden that would buy very little -- the
     * "show all languages" filter exists precisely so nobody is trapped by a
     * wrong guess here.
     */
    private const COUNTRY_LANGUAGES = [
        // Multilingual, where guessing one language would be wrong
        'CM' => ['fr', 'en'],
        'BE' => ['fr', 'nl', 'de'],
        'CH' => ['de', 'fr', 'it'],
        'CA' => ['en', 'fr'],
        'ZA' => ['en'],
        'MA' => ['ar', 'fr'],
        'DZ' => ['ar', 'fr'],
        'TN' => ['ar', 'fr'],
        'MU' => ['en', 'fr'],
        'RW' => ['en', 'fr'],
        'SC' => ['en', 'fr'],
        'MG' => ['fr'],
        'GQ' => ['es', 'fr', 'pt'],
        'LU' => ['fr', 'de'],
        'IE' => ['en'],

        // Francophone Africa
        'CI' => ['fr'], 'SN' => ['fr'], 'GA' => ['fr'], 'CD' => ['fr'], 'CG' => ['fr'],
        'TD' => ['fr', 'ar'], 'CF' => ['fr'], 'BJ' => ['fr'], 'BF' => ['fr'],
        'ML' => ['fr'], 'NE' => ['fr'], 'TG' => ['fr'], 'GN' => ['fr'],
        'DJ' => ['fr', 'ar'], 'KM' => ['fr', 'ar'], 'BI' => ['fr'],

        // Anglophone Africa
        'NG' => ['en'], 'GH' => ['en'], 'KE' => ['en'], 'UG' => ['en'], 'TZ' => ['en'],
        'ZM' => ['en'], 'ZW' => ['en'], 'BW' => ['en'], 'NA' => ['en'], 'MW' => ['en'],
        'SL' => ['en'], 'LR' => ['en'], 'GM' => ['en'], 'SS' => ['en'], 'ET' => ['en'],

        // Lusophone
        'AO' => ['pt'], 'MZ' => ['pt'], 'CV' => ['pt'], 'GW' => ['pt'], 'ST' => ['pt'],
        'BR' => ['pt'], 'PT' => ['pt'],

        // Arabic
        'EG' => ['ar'], 'LY' => ['ar'], 'SD' => ['ar'], 'MR' => ['ar', 'fr'],
        'SO' => ['ar'], 'SA' => ['ar'], 'AE' => ['ar'],

        // Elsewhere the platform is likely to see tenants
        'FR' => ['fr'], 'ES' => ['es'], 'DE' => ['de'], 'IT' => ['it'],
        'NL' => ['nl'], 'GB' => ['en'], 'US' => ['en'], 'AU' => ['en'], 'IN' => ['en'],
    ];

    /**
     * @return string[] Language codes, always including English as a fallback.
     */
    public static function languages(?string $code): array
    {
        $langs = self::COUNTRY_LANGUAGES[strtoupper((string) $code)] ?? [];

        // English is added everywhere: it is the language most security
        // training is published in, and omitting it would leave a tenant in an
        // unlisted country seeing nothing at all.
        if (!in_array('en', $langs, true)) {
            $langs[] = 'en';
        }

        return $langs;
    }

    public static function name(?string $code): ?string
    {
        if (!$code) {
            return null;
        }

        return self::list()[strtoupper($code)] ?? $code;
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::list()[strtoupper($code)]);
    }
}
