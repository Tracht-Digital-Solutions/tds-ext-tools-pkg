<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/ToolsApiDocsTest.php` fails the build if the documented set and the
 * registered set drift apart in either direction.
 */

declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'pattern' => '/tools/catalog',
        'tag' => 'Öffentlich',
        'summary' => 'Der zusammengeführte Tool-Katalog samt AdSense-Konfiguration',
        'description' => 'Die gekoppelte Tools-Site liest diese Antwort mit ihrem auf Tools '
            . 'begrenzten Site-Key und legt sie in ihrem Seiten-Cache ab. Geliefert werden NUR '
            . 'die Übersteuerungen aus der Verwaltung (aktiv, Login nötig, Premium, Preis) '
            . '— die Tool-Liste selbst gehört den Paketen, und keine Antwort von hier '
            . 'kann die Seite leeren.',
        'auth' => 'token',
        'responses' => [
            [
                'status' => 200,
                // Weder `slug` noch `name` stehen drin, und der ads-Block heißt anders,
                // als hier jahrelang dokumentiert war: `publicCatalog()` liefert reine
                // Flags. Der Paritätstest vergleicht nur Methode + Pfad, sieht eine
                // falsche Antwortform also nicht.
                'description' => '`{tools: [{id, enabled, requires_login, is_premium, price_cents}], ads: {enabled, publisherId, slotCatalog, slotTool}}`',
            ],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/registry',
        'tag' => 'Öffentlich',
        'summary' => 'Die gekoppelte Site synchronisiert ihren gebauten Tool-Katalog',
        'description' => 'Die Richtung, in der die Tool-Liste fließt: die Pakete deklarieren '
            . 'die Tools, der Server von `tds-tools-frontend` meldet den gebauten Katalog '
            . 'nach dem Pairing und bei geändertem Katalog-Hash. Die Verwaltung kann die '
            . 'Einträge danach übersteuern. Zugelassen ist nur der an `tools/tools` gebundene '
            . 'Site-Key mit Registry-Scope. Das frühere Registry-Token bleibt ausschließlich '
            . 'für diese Übergangsrelease als serverseitiger Fallback erhalten.',
        'auth' => 'token',
        'params' => [
            ['in' => 'header', 'name' => 'X-TDS-Site-Key', 'type' => 'string', 'required' => true, 'description' => 'Der beim Pairing ausgestellte, ressourcengebundene Site-Key.'],
            ['in' => 'body', 'name' => 'tools', 'type' => 'array', 'required' => true, 'description' => 'Die komponierten Tools aus den Paketen.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, synced: <Anzahl>}`'],
            ['status' => 401, 'description' => 'Site-Key ist ungültig, falsch gebunden oder ohne Registry-Scope.'],
            ['status' => 503, 'description' => 'Weder gekoppelte Site noch Übergangs-Fallback ist konfiguriert.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tools',
        'tag' => 'Verwaltung',
        'summary' => 'Alle Tools mit ihren Übersteuerungen',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => '`{tools: [...]}` — gemeldeter Stand plus Übersteuerungen.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tools/connection',
        'tag' => 'Verbindung',
        'summary' => 'Status der Tools-Site-Verbindung',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => 'Öffentliche Verbindungsdaten ohne Site-Key oder Cache-Token.'],
            ['status' => 404, 'description' => 'Die Tools-Site ist noch nicht gekoppelt.'],
            ['status' => 503, 'description' => 'Der gemeinsame Connection-Store ist nicht verfügbar.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tools/connection/pairing',
        'tag' => 'Verbindung',
        'summary' => 'Tools-Site sicher mit der API verbinden oder neu verbinden',
        'description' => 'Erzeugt eine kurzlebige, einmal verwendbare Freigabe für genau '
            . '`tools/tools` und liefert sie serverseitig an die HTTPS-Origin. Scheitert '
            . 'die direkte Zustellung, enthält die Antwort einen Einrichtungslink, dessen '
            . 'Geheimnis ausschließlich im URL-Fragment steht.',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'body', 'name' => 'origin', 'type' => 'string', 'required' => true, 'description' => 'Reine HTTPS-Origin der Tools-Site, ohne Pfad, Query oder Fragment.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => 'Zustellstatus, geheime-freie Verbindung und gegebenenfalls `fallback_url`.'],
            ['status' => 422, 'description' => 'Origin ungültig oder unsicher.'],
            ['status' => 429, 'description' => 'Pairing-Rate-Limit erreicht.'],
            ['status' => 503, 'description' => 'Connection-Store oder Ziel-Site nicht verfügbar.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/admin/tools/connection',
        'tag' => 'Verbindung',
        'summary' => 'Tools-Site-Verbindung trennen',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, deleted: bool}`; zugehörige Laufzeitverbindung wird entfernt.'],
            ['status' => 503, 'description' => 'Der gemeinsame Connection-Store ist nicht verfügbar.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/tools/{id}',
        'tag' => 'Verwaltung',
        'summary' => 'Übersteuerung eines Tools setzen',
        'description' => 'Speichert die Änderung unabhängig vom Cache-Ergebnis und sendet '
            . 'anschließend ein gezieltes Cache-Ereignis an die gekoppelte Haupt-Site. '
            . '`{id}` hat bewusst kein Zahlenmuster: Tool-Ids sind Slugs.',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id (Slug, keine Zahl).'],
            ['in' => 'body', 'name' => 'enabled', 'type' => 'bool', 'description' => 'Tool auf der Seite anzeigen.'],
            ['in' => 'body', 'name' => 'requires_login', 'type' => 'bool', 'description' => 'Nur für angemeldete Nutzer.'],
            ['in' => 'body', 'name' => 'is_premium', 'type' => 'bool', 'description' => 'Kostenpflichtig (setzt `requires_login` voraus).'],
            ['in' => 'body', 'name' => 'price_cents', 'type' => 'int', 'description' => 'Früherer Einmalpreis in Cent; nicht mehr verkauft (Preise tragen die Premium-Stufen).'],
            ['in' => 'body', 'name' => 'blog_slug', 'type' => 'string', 'description' => 'Ratgeber-Artikel (Blog-Slug); leer hebt die Verknüpfung auf, ein ungültiger Wert ebenso.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, cache_status, cached, rebuilt, skipped, failed, unknownEvents}`.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
            ['status' => 404, 'description' => 'Unbekannte Tool-Id, oder nichts zu ändern.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/guides',
        'tag' => 'Öffentlich',
        'summary' => 'Die im Panel gepflegten Texte der Tool-Seiten',
        'description' => 'Mit dem ressourcengebundenen Site-Key geschützt wie `/tools/catalog`. '
            . 'Liefert je Tool-Id die übersteuerten Texte einer Sprache: Name, '
            . 'Beschreibung, SEO-Felder und den Ratgeber (Einleitung, Anwendungsfälle, '
            . 'Schritte, FAQ, Datenschutzhinweis, verwandte Tools). **Alles ist eine '
            . 'Übersteuerung**: fehlt ein Feld, rendert die Site den im Repo '
            . 'mitgelieferten Text, sodass eine leere oder nicht erreichbare Datenbank '
            . 'eine Tool-Seite niemals leeren kann.',
        'auth' => 'token',
        'responses' => [
            ['status' => 200, 'description' => '`{guides: {"<tool-id>": {…}}}` — leer, wenn nichts gepflegt ist.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tools/guides',
        'tag' => 'Verwaltung',
        'summary' => 'Alle gepflegten Tool-Texte, für die Bearbeitungsoberfläche',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => '`{guides: [{tool_id, lang, …}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/tools/guides/{id}/{lang}',
        'tag' => 'Verwaltung',
        'summary' => 'Text und Ratgeber eines Tools in einer Sprache speichern',
        'description' => 'Ein weggelassenes Feld wird als NULL gespeichert, heißt also '
            . '„wieder den mitgelieferten Text verwenden" — sonst ließe sich eine '
            . 'Übersteuerung ohne Datenbankzugriff nicht mehr zurücknehmen. Nach dem '
            . 'Speichern wird der Seiten-Cache der betroffenen Tool-Seite neu gebaut.',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id (Slug, keine Zahl).'],
            ['in' => 'path', 'name' => 'lang', 'type' => 'string', 'required' => true, 'description' => '`de` oder `en`.'],
            ['in' => 'body', 'name' => 'name', 'type' => 'string', 'description' => 'Anzeigename; leer = der Name aus dem Paket-Manifest.'],
            ['in' => 'body', 'name' => 'description', 'type' => 'string', 'description' => 'Kurzbeschreibung für Katalog und Kopfbereich.'],
            ['in' => 'body', 'name' => 'seo_title', 'type' => 'string', 'description' => 'Seitentitel (Budget: 60 Zeichen).'],
            ['in' => 'body', 'name' => 'seo_description', 'type' => 'string', 'description' => 'Meta-Description (Budget: 80–160 Zeichen).'],
            ['in' => 'body', 'name' => 'intro', 'type' => 'array', 'description' => 'Absätze der Einleitung.'],
            ['in' => 'body', 'name' => 'use_cases', 'type' => 'array', 'description' => 'Anwendungsfälle.'],
            ['in' => 'body', 'name' => 'steps', 'type' => 'array', 'description' => 'Schritte — speisen auch das HowTo-JSON-LD.'],
            ['in' => 'body', 'name' => 'faq', 'type' => 'array', 'description' => 'Fragen und Antworten — speisen auch das FAQPage-JSON-LD.'],
            ['in' => 'body', 'name' => 'related', 'type' => 'array', 'description' => 'Slugs verwandter Tools.'],
            ['in' => 'body', 'name' => 'privacy', 'type' => 'string', 'description' => 'Datenschutzhinweis unter dem Werkzeug.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, cache_status, cached, rebuilt, skipped, failed, unknownEvents}`. Der Text bleibt auch bei Cache-Fehler gespeichert.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
            ['status' => 422, 'description' => 'Sprache weder `de` noch `en`.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/admin/tools/guides/{id}/{lang}',
        'tag' => 'Verwaltung',
        'summary' => 'Übersteuerung zurücknehmen (der mitgelieferte Text greift wieder)',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id (Slug, keine Zahl).'],
            ['in' => 'path', 'name' => 'lang', 'type' => 'string', 'required' => true, 'description' => '`de` oder `en`.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, cache_status, cached, rebuilt, skipped, failed, unknownEvents}`. Das Entfernen bleibt auch bei Cache-Fehler gespeichert.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tools/cache/rebuild',
        'tag' => 'Verwaltung',
        'summary' => 'Seiten-Cache der Tools-Site neu bauen',
        'description' => 'Rendert Seiten aus bereits gespeichertem Inhalt neu; ein Deploy '
            . 'oder GitHub-Aufruf findet nicht statt. Optional `tool_id` im Rumpf, sonst '
            . 'wird der Katalog der gekoppelten Haupt-Site aktualisiert.',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'body', 'name' => 'tool_id', 'type' => 'string', 'description' => 'Optional: nur die Seite dieses Tools aktualisieren.'],
            ['in' => 'body', 'name' => 'event', 'type' => 'string', 'description' => '`settings` aktualisiert Katalog-/globale Seiten.'],
        ],
        'responses' => [
            ['status' => 202, 'description' => 'Cache vollständig aktualisiert (`cached: true`).'],
            ['status' => 422, 'description' => 'Ungültige Legacy-Origin während der Übergangsrelease.'],
            ['status' => 502, 'description' => 'Remote-, Transport- oder Teilfehler; Details stehen in `failed`/`skipped`.'],
            ['status' => 503, 'description' => 'Tools-Site oder Cache-Verbindung fehlt.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/summary',
        'tag' => 'Verwaltung',
        'summary' => 'Kennzahlen des Katalogs für das Dashboard-Widget',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => 'Zählwerte je Kategorie plus `ads` (ob AdSense aktiv ist).'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/entitlement',
        'tag' => 'Premium',
        'summary' => 'Prüfen, ob der angemeldete Nutzer ein Premium-Tool nutzen darf',
        'description' => 'Die Freischaltung hängt an `app_user_id`, nicht an Browser oder '
            . 'Gerät. Admins dürfen jedes Premium-Tool ohne Kauf. Die Premium-Tools '
            . 'laufen im Browser, die Prüfung ist also eine Bezahlschranke und kein '
            . 'Kopierschutz.',
        'auth' => 'session',
        'params' => [
            ['in' => 'query', 'name' => 'tool', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{entitled: bool, authenticated: true}`'],
            ['status' => 401, 'description' => '`{entitled: false, authenticated: false}` — die Seite zeigt daraufhin den Login-Hinweis statt eines Fehlers.'],
            ['status' => 422, 'description' => '`tool` fehlt.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/checkout',
        'tag' => 'Premium',
        'summary' => 'Ein Werkzeug freischalten: Monatsabo der günstigsten passenden Stufe',
        'description' => 'Einzelkäufe werden seit 2026-10-24 nicht mehr verkauft (frühere bleiben gültig). '
            . 'Die Route öffnet den Stripe-Checkout im Abo-Modus für die günstigste aktive Premium-Stufe, '
            . 'die das Werkzeug enthält. Preis und Name kommen aus der Stufe, nie aus der Anfrage.',
        'auth' => 'session',
        'params' => [
            ['in' => 'body', 'name' => 'tool', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id, z. B. `pdf-komprimieren` oder `design-system-studio`.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{url}` — die Checkout-Seite von Stripe.'],
            ['status' => 400, 'description' => 'Kein Werkzeug angegeben, oder keine Stufe enthält es (`code: no_tier`).'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Werkzeug deaktiviert.'],
            ['status' => 409, 'description' => 'Bereits freigeschaltet, oder es läuft schon ein Abo (`code: has_subscription`).'],
            ['status' => 502, 'description' => 'Stripe hat die Session abgelehnt.'],
            ['status' => 503, 'description' => 'Kein Stripe Secret Key konfiguriert.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/tiers',
        'tag' => 'Premium',
        'summary' => 'Die Premium-Stufen im Verkauf (öffentlich)',
        'description' => 'Name, Werbetext, Leistungen (DE/EN), fester Monatspreis in Cent und die freigeschalteten '
            . 'Werkzeuge jeder aktiven Stufe. Bewusst ohne Site-Key: Preise sind öffentlich, und ein vor dieser '
            . 'Route gekoppelter Schlüssel erlaubt sie nicht. Fällt bei einem DB-Fehler auf eine leere Liste zurück.',
        'auth' => 'public',
        'params' => [],
        'responses' => [
            ['status' => 200, 'description' => '`{tiers: [{slug, name, name_en, tagline, tagline_en, features, features_en, price_cents, interval, tool_ids, highlight}], currency}`'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/premium/status',
        'tag' => 'Premium',
        'summary' => 'Premium-Stand des Besuchers',
        'description' => '`premium` ist wahr für Admins und bei einem Abo, das Stripe als zahlend führt '
            . '(active, trialing, past_due). Einzelkäufe schalten ihr Werkzeug frei, machen das Konto aber nicht Premium.',
        'auth' => 'public',
        'params' => [],
        'responses' => [
            ['status' => 200, 'description' => '`{authenticated, admin, premium, subscriptions: [{tier, name, name_en, status, current_period_end, cancel_at_period_end}], tools}`'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/subscribe',
        'tag' => 'Premium',
        'summary' => 'Eine Premium-Stufe monatlich abonnieren',
        'description' => 'Öffnet den Stripe-Checkout im Abo-Modus (Monatsabrechnung). Nutzer und Stufe reisen in den '
            . 'Metadaten der Session und des Abos (`module=tools`). Ein Konto hat höchstens ein laufendes Abo.',
        'auth' => 'session',
        'params' => [
            ['in' => 'body', 'name' => 'tier', 'type' => 'string', 'required' => true, 'description' => 'Kurzname der Stufe.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{url}` — die Checkout-Seite von Stripe.'],
            ['status' => 401, 'description' => 'Keine Sitzung (`code: login_required`).'],
            ['status' => 404, 'description' => 'Unbekannte oder deaktivierte Stufe.'],
            ['status' => 409, 'description' => 'Es läuft schon ein Abo (`code: has_subscription`).'],
            ['status' => 502, 'description' => 'Stripe hat die Session abgelehnt.'],
            ['status' => 503, 'description' => 'Kein Stripe Secret Key konfiguriert.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/billing-portal',
        'tag' => 'Premium',
        'summary' => 'Abo verwalten oder kündigen (Stripe-Kundenportal)',
        'description' => 'Kündigen, Zahlungsmittel ändern, Rechnungen laden. Das Kundenportal muss in Stripe einmal aktiviert sein.',
        'auth' => 'session',
        'params' => [],
        'responses' => [
            ['status' => 201, 'description' => '`{url}` — das Kundenportal.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 404, 'description' => 'Zu diesem Konto gibt es kein Abo (`code: no_subscription`).'],
            ['status' => 502, 'description' => 'Stripe hat abgelehnt.'],
            ['status' => 503, 'description' => 'Kein Stripe Secret Key konfiguriert.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tools/tiers',
        'tag' => 'Premium',
        'summary' => 'Alle Premium-Stufen mit laufenden Abos',
        'auth' => 'session',
        'permission' => 'tools:manage',
        'params' => [],
        'responses' => [
            ['status' => 200, 'description' => '`{tiers: [{id, slug, name, …, price_cents, tool_ids, active, highlight, sort_order, subscribers}], currency}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Ohne `tools:manage`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tools/tiers',
        'tag' => 'Premium',
        'summary' => 'Premium-Stufe anlegen',
        'description' => 'Preis in Cent (0,50 € bis 10.000 €), mindestens ein Werkzeug. Danach wird der Katalog-Cache der Site erneuert.',
        'auth' => 'session',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Anzeigename (DE).'],
            ['in' => 'body', 'name' => 'slug', 'type' => 'string', 'description' => 'Kurzname; leer = aus dem Namen.'],
            ['in' => 'body', 'name' => 'price_cents', 'type' => 'int', 'required' => true, 'description' => 'Monatspreis in Cent.'],
            ['in' => 'body', 'name' => 'tool_ids', 'type' => 'string[]', 'required' => true, 'description' => 'Freigeschaltete Werkzeuge.'],
            ['in' => 'body', 'name' => 'tagline', 'type' => 'string', 'description' => 'Werbetext (DE); `tagline_en`, `name_en`, `features`, `features_en` analog.'],
            ['in' => 'body', 'name' => 'active', 'type' => 'bool', 'description' => 'Im Verkauf (Standard ja).'],
            ['in' => 'body', 'name' => 'highlight', 'type' => 'bool', 'description' => 'Als Empfehlung hervorheben.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{ok, tier, …cache}`'],
            ['status' => 409, 'description' => 'Kurzname vergeben.'],
            ['status' => 422, 'description' => 'Ungültige Angaben (Meldung im Feld `error`).'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/tools/tiers/{id:[0-9]+}',
        'tag' => 'Premium',
        'summary' => 'Premium-Stufe ändern',
        'description' => 'Ganze Stufe wie beim Anlegen. Eine Preisänderung gilt für neue Abos; laufende behalten ihren Preis.',
        'auth' => 'session',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Stufen-Id.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok, tier, …cache}`'],
            ['status' => 404, 'description' => 'Stufe nicht gefunden.'],
            ['status' => 409, 'description' => 'Kurzname vergeben.'],
            ['status' => 422, 'description' => 'Ungültige Angaben.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/admin/tools/tiers/{id:[0-9]+}',
        'tag' => 'Premium',
        'summary' => 'Premium-Stufe löschen',
        'description' => 'Nur ohne Abos. Eine Stufe mit Abos wird stattdessen deaktiviert.',
        'auth' => 'session',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Stufen-Id.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok, …cache}`'],
            ['status' => 404, 'description' => 'Stufe nicht gefunden.'],
            ['status' => 409, 'description' => 'Die Stufe hat Abos (`code: has_subscriptions`).'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/stripe-webhook',
        'tag' => 'Premium',
        'summary' => 'Stripe-Webhook (Kauf abgeschlossen → Freischaltung)',
        'description' => 'Unauthentifiziert, aber signaturgeprüft. Reagiert auf '
            . '`checkout.session.completed` und legt die Freischaltung in '
            . '`tools_entitlement` an. Andere Ereignistypen werden mit 200 quittiert, '
            . 'damit Stripe sie nicht endlos wiederholt.',
        'auth' => 'token',
        'params' => [
            ['in' => 'header', 'name' => 'Stripe-Signature', 'type' => 'string', 'required' => true, 'description' => 'Von Stripe erzeugte Signatur über den rohen Body.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{received: true}` — auch für nicht behandelte Ereignistypen.'],
            ['status' => 400, 'description' => 'Signatur ungültig.'],
            ['status' => 503, 'description' => 'Kein Webhook-Secret konfiguriert.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/studio/access',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Was der Besucher im Designsystem-Studio darf',
        'description' => 'Liefert, ob das Studio aktiv ist, ob der Besucher angemeldet ist und Premium '
            . 'besitzt, welche Exportformate kostenlos oder Premium sind (Einstellungen → Tools), '
            . 'den Premium-Preis und die Projektgrenze. Gäste bekommen eine Antwort, keinen Fehler. '
            . 'Der Export-Endpunkt der Tools-Site fragt hier mit dem Cookie des Besuchers nach, '
            . 'bevor er ein Premium-Format erzeugt.',
        'auth' => 'public',
        'responses' => [
            ['status' => 200, 'description' => '`{enabled, authenticated, entitled, formats: {css: "free"|"premium", …}, priceCents, currency, analysisEnabled, projectLimit}`'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/studio/projects',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Gespeicherte Studio-Projekte des Nutzers auflisten',
        'description' => 'Nur mit Premium (Freischaltung `design-system-studio`) oder als Admin. '
            . 'Jede Abfrage ist auf den eigenen Nutzer beschränkt.',
        'auth' => 'session',
        'responses' => [
            ['status' => 200, 'description' => '`{projects: [{id, name, updated_at}]}`'],
            ['status' => 401, 'description' => 'Nicht angemeldet.'],
            ['status' => 403, 'description' => '`code: premium_required` oder `disabled`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/studio/projects',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Ein Studio-Projekt speichern',
        'description' => 'Der Server prüft nur die Hülle (Objekt mit bekannter `schema`-Version, Name, '
            . 'höchstens 512 KB) und speichert das Projekt unverändert.',
        'auth' => 'session',
        'params' => [
            ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Projektname, höchstens 120 Zeichen.'],
            ['in' => 'body', 'name' => 'data', 'type' => 'object', 'required' => true, 'description' => 'Das versionierte Studio-Projekt.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{project: {id, name, updated_at}}`'],
            ['status' => 401, 'description' => 'Nicht angemeldet.'],
            ['status' => 403, 'description' => '`code: premium_required` oder `disabled`.'],
            ['status' => 409, 'description' => 'Projektgrenze erreicht (`code: limit`).'],
            ['status' => 422, 'description' => 'Ungültiges Projekt (`code: invalid_project`).'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/studio/projects/{id:[0-9]+}',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Ein eigenes Studio-Projekt laden',
        'auth' => 'session',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Projekt-Id.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{project: {id, name, data, updated_at}}`'],
            ['status' => 404, 'description' => 'Nicht vorhanden oder nicht das eigene Projekt.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/tools/studio/projects/{id:[0-9]+}',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Ein eigenes Studio-Projekt überschreiben',
        'auth' => 'session',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Projekt-Id.'],
            ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Projektname.'],
            ['in' => 'body', 'name' => 'data', 'type' => 'object', 'required' => true, 'description' => 'Das versionierte Studio-Projekt.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{project: {id, name, updated_at}}`'],
            ['status' => 404, 'description' => 'Nicht vorhanden oder nicht das eigene Projekt.'],
            ['status' => 422, 'description' => 'Ungültiges Projekt.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/tools/studio/projects/{id:[0-9]+}',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Ein eigenes Studio-Projekt löschen',
        'auth' => 'session',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Projekt-Id.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 404, 'description' => 'Nicht vorhanden oder nicht das eigene Projekt.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/studio/analyze',
        'tag' => 'Designsystem-Studio',
        'summary' => 'Genau eine HTTPS-Seite samt Stylesheets für die Stilanalyse laden',
        'description' => 'Lädt ausschließlich die angegebene Seite und die von ihr verlinkten HTTPS-'
            . 'Stylesheets (eine @import-Ebene) — kein Crawling, keine weiteren HTML-Seiten, kein '
            . 'JavaScript. Jede Adresse wird geprüft: nur Port 443, keine internen oder reservierten '
            . 'Netze; jede DNS-Antwort muss öffentlich sein, die Verbindung wird auf die geprüfte '
            . 'Adresse festgelegt (DNS-Rebinding). Leitet die Seite weiter, wird das Ziel gemeldet, '
            . 'nicht geladen. Größen-, Zeit- und Mengengrenzen; Ratenbegrenzung pro Adresse '
            . '(Einstellungen → Tools). Die Auswertung selbst geschieht im Browser.',
        'auth' => 'public',
        'params' => [
            ['in' => 'body', 'name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'HTTPS-Adresse der Seite.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{url, finalUrl, title, html, stylesheets: [{url, css}], skipped: [{url, reason}]}` — `html` ohne Skripte und Eventhandler.'],
            ['status' => 403, 'description' => 'Studio oder Analyse abgeschaltet (`code: disabled`).'],
            ['status' => 413, 'description' => 'Seite zu groß (`code: too_large`).'],
            ['status' => 422, 'description' => '`code: invalid_url`, `blocked_host`, `redirect` (mit `location`) oder `not_html`.'],
            ['status' => 429, 'description' => 'Zu viele Analysen (`code: rate_limited`).'],
            ['status' => 502, 'description' => 'Seite nicht ladbar (`code: fetch_failed`).'],
            ['status' => 504, 'description' => 'Zeitüberschreitung (`code: timeout`).'],
        ],
    ],
];
