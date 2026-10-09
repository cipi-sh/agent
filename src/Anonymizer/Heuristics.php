<?php

namespace Cipi\Agent\Anonymizer;

/**
 * Column-name heuristics shared by the config scanner (`cipi:init-anonymize
 * --scan`) and the audit (`cipi:anonymize-audit`): which columns look like
 * personal data, and which transformation fits them. Suggestions only — a
 * human reviews the file before the first dump.
 */
final class Heuristics
{
    /**
     * Tables whose rows are operational noise and leak sessions, tokens or
     * personal data: the scanner proposes emptying them in the dump.
     */
    public const TRUNCATE_CANDIDATES = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches',
        'password_reset_tokens', 'password_resets', 'personal_access_tokens',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'pulse_aggregates', 'pulse_entries', 'pulse_values',
        'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes',
        'webhook_calls', 'activity_log', 'audits', 'notifications',
    ];

    /**
     * [regex on the column name, transformation]. First match wins. Foreign
     * keys (`*_id`) and primary keys are never touched.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const RULES = [
        ['/^(remember_token|two_factor_secret|two_factor_recovery_codes)$/', 'null'],
        ['/^(password|password_hash|passwd|hashed_password)$/', 'password'],
        ['/(^|_)(api_token|access_token|refresh_token|secret|secret_key|api_key|private_key|token)$/', 'fakeToken'],
        ['/^e-?mail$|_e-?mail$|^e-?mail_address$|^contact_e-?mail$/', 'fakeEmail'],
        ['/^(first_?name|given_?name|forename)$/', 'fakeFirstName'],
        ['/^(last_?name|surname|family_?name)$/', 'fakeLastName'],
        ['/^(full_?name|display_?name|contact_?name|customer_?name|owner_?name|billing_?name|shipping_?name|recipient_?name|cardholder_?name|holder_?name|legal_?name|person_?name|real_?name|name)$/', 'fakeName'],
        ['/^(user_?name|nick_?name|nick|handle|screen_?name|login)$/', 'fakeUserName'],
        ['/^(company|company_?name|organi[sz]ation|organi[sz]ation_?name|employer|business_?name)$/', 'fakeCompany'],
        ['/^(job_?title|position|occupation)$/', 'fakeJobTitle'],
        ['/^(phone|phone_?number|mobile|mobile_?number|mobile_?phone|telephone|tel|fax|cell|cellphone|whatsapp)$|_(phone|mobile|telephone|fax)(_number)?$/', 'fakePhoneNumber'],
        ['/^(ip|ip_?address|ip_?addr|last_?ip|login_?ip|remote_?ip|client_?ip|last_?login_?ip|created_?ip|registration_?ip)$|_ip$|_ip_?address$/', 'fakeIpv4'],
        ['/^(mac|mac_?address)$|_mac$/', 'fakeMacAddress'],
        ['/^(street|street_?address|address_?line_?1|address_?1|address|billing_?address|shipping_?address|home_?address|mailing_?address|residence|address_?line_?2|address_?2)$|_address$/', 'fakeStreetAddress'],
        ['/^(city|town|locality|billing_?city|shipping_?city)$|_city$/', 'fakeCity'],
        ['/^(zip|zip_?code|zipcode|postcode|post_?code|postal_?code|billing_?zip|shipping_?zip|billing_?postcode|shipping_?postcode)$|_(zip|postcode|postal_code)$/', 'fakePostcode'],
        ['/^(state|province|region|county|billing_?state|shipping_?state)$|_state$|_province$/', 'fakeState'],
        ['/^(country|nation|billing_?country|shipping_?country)$|_country$/', 'fakeCountry'],
        ['/^(country_?code|iso_?country)$|_country_?code$/', 'fakeCountryCode'],
        ['/^(user_?agent|browser|ua)$|_user_?agent$/', 'fakeUserAgent'],
        ['/^(birth_?date|birthday|birthdate|dob|date_?of_?birth|born_?at|born_?on)$/', 'fakeDate'],
        ['/^(iban|bank_?account|account_?number|bank_?account_?number)$/', 'fakeIban'],
        ['/^(card_?number|cc_?number|credit_?card|credit_?card_?number|pan)$/', 'fakeCreditCardNumber'],
        ['/^(cvv|cvc|cvv2|card_?cvv|security_?code)$/', 'numerify:###'],
        ['/^(pm_?last_?four|last_?four|last4|card_?last_?four|card_?last4)$/', 'numerify:####'],
        ['/^(ssn|social_?security|social_?security_?number|national_?id|national_?insurance|nin|passport|passport_?number|tax_?id|tax_?code|tax_?number|vat|vat_?number|vat_?id|fiscal_?code|codice_?fiscal[eo]|partita_?iva|piva|cf|nif|nie|cpf|cnpj|dni|sin|driver_?licen[cs]e|licen[cs]e_?number|id_?number|identity_?number|document_?number)$/', 'fakeIdentifier'],
        ['/^(lat|latitude|geo_?lat)$|_lat(itude)?$/', 'fakeLatitude'],
        ['/^(lng|lon|long|longitude|geo_?lng|geo_?lon)$|_(lng|lon|longitude)$/', 'fakeLongitude'],
        ['/^(website|url|homepage|web|site|linkedin|facebook|twitter|instagram|github|social_?link|profile_?url)$|_url$/', 'fakeUrl'],
        ['/^(bio|biography|about|about_?me|notes|note|internal_?notes|customer_?notes|comment|comments|remarks|message|body|feedback|complaint|review|signature)$|_notes?$|_comments?$/', 'fakeParagraph'],
        ['/^(gender|sex)$/', 'keep'],
    ];

    /** Column types that are never personal data worth transforming. */
    private const SKIP_TYPES = ['json', 'jsonb', 'blob', 'binary', 'bytea', 'bit', 'geometry', 'point'];

    public static function suggest(string $table, string $column, ?string $type = null): ?string
    {
        $col = strtolower($column);
        if ($col === 'id' || $col === 'uuid' || $col === 'ulid' || str_ends_with($col, '_id') || str_ends_with($col, '_uuid') || str_ends_with($col, '_ulid')) {
            return null;
        }
        if (in_array($col, ['created_at', 'updated_at', 'deleted_at', 'email_verified_at'], true)) {
            return null;
        }
        if ($type !== null && in_array(strtolower($type), self::SKIP_TYPES, true)) {
            return null;
        }

        foreach (self::RULES as [$pattern, $transformation]) {
            if (preg_match($pattern, $col)) {
                if ($transformation === 'keep') {
                    return null;
                }
                if ($transformation === 'fakeName' && $col === 'name' && ! self::tableLooksPersonal($table)) {
                    // `name` on products, roles or tags is not a person.
                    return null;
                }

                return $transformation;
            }
        }

        return null;
    }

    public static function tableLooksPersonal(string $table): bool
    {
        return (bool) preg_match('/(user|customer|client|member|person|people|contact|employee|staff|account|profile|subscriber|lead|patient|student|teacher|author|guest|applicant|candidate|recipient|address|donor|supplier|vendor|partner|owner|tenant|driver|passenger|attendee|participant)/i', $table);
    }

    public static function isTruncateCandidate(string $table): bool
    {
        $t = strtolower($table);
        if (in_array($t, self::TRUNCATE_CANDIDATES, true)) {
            return true;
        }

        return (bool) preg_match('/^(telescope_|pulse_|horizon_|sessions$|.*_sessions$|.*_tokens$|.*_cache$)/', $t);
    }
}
