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
            ['in' => 'body', 'name' => 'price_cents', 'type' => 'int', 'description' => 'Einmalpreis in Cent.'],
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
        'summary' => 'Stripe-Checkout für ein Premium-Tool starten',
        'description' => 'Preis und Name kommen aus dem Katalog, nie aus der Anfrage — sonst '
            . 'könnte der Browser seinen eigenen Preis vorgeben. Die Nutzer-Id reist als '
            . '`client_reference_id` mit, damit der Webhook die Freischaltung zuordnen '
            . 'kann. Ein Stripe-Fehler wird als 502 durchgereicht.',
        'auth' => 'session',
        'params' => [
            ['in' => 'body', 'name' => 'tool', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id. Muss Premium sein und einen Preis > 0 haben.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{url}` — die Checkout-Seite von Stripe.'],
            ['status' => 400, 'description' => 'Unbekanntes Tool, nicht Premium, oder Preis 0.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 409, 'description' => 'Bereits freigeschaltet.'],
            ['status' => 502, 'description' => 'Stripe hat die Session abgelehnt.'],
            ['status' => 503, 'description' => 'Kein Stripe Secret Key konfiguriert.'],
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
];
