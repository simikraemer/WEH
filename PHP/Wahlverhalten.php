<?php
/**
 * Wahlverhalten | WEH e.V.
 * Anzeige und Berechnung direkt in PHP (methodisch entsprechend nvc.py).
 * Keine individuellen Wahldaten werden an den Browser ausgegeben.
 * PHP 7.4+, mysqli, json, session. Keine JS-/CSS-CDNs erforderlich.
 * Ablage: /WEH/PHP/Wahlverhalten.php
 */

function wv_german_aliases() {
    // Dieselben Laender-/Alias-Bezeichnungen wie read_bewerber.py.
    return [
        'af' => 'Afghanistan',
        'al' => 'Albanien',
        'dz' => 'Algerien',
        'ad' => 'Andorra',
        'ao' => 'Angola',
        'ag' => 'Antigua und Barbuda',
        'ar' => 'Argentinien',
        'am' => 'Armenien',
        'au' => 'Australien',
        'at' => 'Österreich',
        'az' => 'Aserbaidschan',
        'bs' => 'Bahamas',
        'bh' => 'Bahrain',
        'bd' => 'Bangladesch',
        'bb' => 'Barbados',
        'by' => 'Belarus|Weißrussland|Weissrussland',
        'be' => 'Belgien',
        'bz' => 'Belize',
        'bj' => 'Benin',
        'bt' => 'Bhutan',
        'bo' => 'Bolivien',
        'ba' => 'Bosnien und Herzegowina|Bosnien-Herzegowina',
        'bw' => 'Botswana',
        'br' => 'Brasilien',
        'bn' => 'Brunei|Brunei Darussalam',
        'bg' => 'Bulgarien',
        'bf' => 'Burkina Faso',
        'bi' => 'Burundi',
        'cv' => 'Kap Verde|Cabo Verde|Kapverden',
        'kh' => 'Kambodscha',
        'cm' => 'Kamerun',
        'ca' => 'Kanada',
        'cf' => 'Zentralafrikanische Republik',
        'td' => 'Tschad',
        'cl' => 'Chile',
        'cn' => 'China|Volksrepublik China',
        'co' => 'Kolumbien',
        'km' => 'Komoren',
        'cr' => 'Costa Rica',
        'hr' => 'Kroatien',
        'cu' => 'Kuba',
        'cy' => 'Zypern',
        'cz' => 'Tschechien|Tschechische Republik',
        'ci' => 'Elfenbeinküste|Côte d’Ivoire|Cote d\'Ivoire',
        'cd' => 'Demokratische Republik Kongo|Kongo-Kinshasa|DR Kongo|Kongo (Demokratische Republik)',
        'cg' => 'Republik Kongo|Kongo-Brazzaville|Kongo (Republik)',
        'dk' => 'Dänemark',
        'dj' => 'Dschibuti',
        'dm' => 'Dominica',
        'do' => 'Dominikanische Republik',
        'ec' => 'Ecuador|Ekuador',
        'eg' => 'Ägypten',
        'sv' => 'El Salvador',
        'gq' => 'Äquatorialguinea',
        'er' => 'Eritrea',
        'ee' => 'Estland',
        'sz' => 'Eswatini|Swasiland',
        'et' => 'Äthiopien',
        'fj' => 'Fidschi',
        'fi' => 'Finnland',
        'fr' => 'Frankreich',
        'ga' => 'Gabun',
        'gm' => 'Gambia',
        'ge' => 'Georgien',
        'de' => 'Deutschland|Bundesrepublik Deutschland|BRD|Germany',
        'gh' => 'Ghana',
        'gr' => 'Griechenland',
        'gt' => 'Guatemala',
        'gn' => 'Guinea',
        'gw' => 'Guinea-Bissau',
        'gy' => 'Guyana',
        'ht' => 'Haiti',
        'hn' => 'Honduras',
        'hu' => 'Ungarn',
        'is' => 'Island',
        'in' => 'Indien',
        'id' => 'Indonesien',
        'ir' => 'Iran|Islamische Republik Iran',
        'iq' => 'Irak',
        'ie' => 'Irland',
        'il' => 'Israel',
        'it' => 'Italien',
        'jm' => 'Jamaika',
        'jp' => 'Japan',
        'jo' => 'Jordanien',
        'kz' => 'Kasachstan',
        'ke' => 'Kenia',
        'ki' => 'Kiribati',
        'xk' => 'Kosovo|Republik Kosovo',
        'kw' => 'Kuwait',
        'kg' => 'Kirgisistan|Kirgisien|Kirgisstan',
        'la' => 'Laos',
        'lv' => 'Lettland',
        'lb' => 'Libanon',
        'ls' => 'Lesotho',
        'lr' => 'Liberia',
        'ly' => 'Libyen',
        'li' => 'Liechtenstein',
        'lt' => 'Litauen',
        'lu' => 'Luxemburg',
        'mg' => 'Madagaskar',
        'mw' => 'Malawi',
        'my' => 'Malaysia',
        'mv' => 'Malediven',
        'ml' => 'Mali',
        'mt' => 'Malta',
        'mh' => 'Marshallinseln',
        'mr' => 'Mauretanien',
        'mu' => 'Mauritius',
        'mx' => 'Mexiko',
        'md' => 'Moldau|Republik Moldau|Moldawien|Moldova',
        'mc' => 'Monaco',
        'mn' => 'Mongolei',
        'me' => 'Montenegro',
        'ma' => 'Marokko',
        'mz' => 'Mosambik',
        'mm' => 'Myanmar|Birma|Burma',
        'na' => 'Namibia',
        'nr' => 'Nauru',
        'np' => 'Nepal',
        'nl' => 'Niederlande|Holland',
        'nz' => 'Neuseeland',
        'ni' => 'Nicaragua',
        'ne' => 'Niger',
        'ng' => 'Nigeria',
        'kp' => 'Nordkorea|Demokratische Volksrepublik Korea',
        'mk' => 'Nordmazedonien|Mazedonien|Republik Nordmazedonien',
        'no' => 'Norwegen',
        'om' => 'Oman',
        'pk' => 'Pakistan',
        'pw' => 'Palau',
        'pa' => 'Panama',
        'pg' => 'Papua-Neuguinea',
        'py' => 'Paraguay',
        'pe' => 'Peru',
        'ph' => 'Philippinen',
        'pl' => 'Polen',
        'pt' => 'Portugal',
        'qa' => 'Katar',
        'ro' => 'Rumänien',
        'ru' => 'Russland|Russische Föderation',
        'rw' => 'Ruanda',
        'kn' => 'St. Kitts und Nevis|Sankt Kitts und Nevis',
        'lc' => 'St. Lucia|Sankt Lucia',
        'vc' => 'St. Vincent und die Grenadinen|Sankt Vincent und die Grenadinen',
        'ws' => 'Samoa',
        'sm' => 'San Marino',
        'st' => 'São Tomé und Príncipe|Sao Tome und Principe',
        'sa' => 'Saudi-Arabien|Saudi Arabien',
        'sn' => 'Senegal',
        'rs' => 'Serbien',
        'sc' => 'Seychellen',
        'sl' => 'Sierra Leone',
        'sg' => 'Singapur',
        'sk' => 'Slowakei',
        'si' => 'Slowenien',
        'sb' => 'Salomonen|Salomoninseln',
        'so' => 'Somalia',
        'za' => 'Südafrika',
        'kr' => 'Südkorea|Republik Korea',
        'ss' => 'Südsudan',
        'es' => 'Spanien',
        'lk' => 'Sri Lanka',
        'ps' => 'Palästina|Staat Palästina|Palästinensische Gebiete',
        'sd' => 'Sudan',
        'sr' => 'Suriname',
        'se' => 'Schweden',
        'ch' => 'Schweiz',
        'sy' => 'Syrien|Arabische Republik Syrien',
        'tw' => 'Taiwan',
        'tj' => 'Tadschikistan',
        'tz' => 'Tansania',
        'th' => 'Thailand',
        'tl' => 'Osttimor|Timor-Leste',
        'tg' => 'Togo',
        'to' => 'Tonga',
        'tt' => 'Trinidad und Tobago',
        'tn' => 'Tunesien',
        'tm' => 'Turkmenistan',
        'tr' => 'Türkei|Türkiye|Tuerkei|Turkey',
        'tv' => 'Tuvalu',
        'ug' => 'Uganda',
        'ua' => 'Ukraine',
        'ae' => 'Vereinigte Arabische Emirate|VAE',
        'gb' => 'Vereinigtes Königreich|Großbritannien|Grossbritannien|England|Schottland|Wales|Nordirland|UK|United Kingdom',
        'us' => 'USA|Vereinigte Staaten|Vereinigte Staaten von Amerika|Amerika|United States|United States of America',
        'uy' => 'Uruguay',
        'uz' => 'Usbekistan',
        'vu' => 'Vanuatu',
        'va' => 'Vatikan|Vatikanstadt|Heiliger Stuhl',
        've' => 'Venezuela',
        'vn' => 'Vietnam',
        'ye' => 'Jemen',
        'zm' => 'Sambia',
        'zw' => 'Simbabwe',
        'hk' => 'Hongkong|Hong Kong',
        'mo' => 'Macau|Macao',
        'pr' => 'Puerto Rico',
        'fo' => 'Färöer|Färöer-Inseln',
        'gl' => 'Grönland',
        're' => 'Réunion|Reunion',
        'nc' => 'Neukaledonien',
        'pf' => 'Französisch-Polynesien',
        'gf' => 'Französisch-Guayana',
        'gp' => 'Guadeloupe',
        'mq' => 'Martinique',
        'yt' => 'Mayotte',
    ];
}

function wv_continent_names() {
    return [
        'Africa' => 'Afrika', 'Asia' => 'Asien', 'Europe' => 'Europa',
        'North America' => 'Nordamerika', 'South America' => 'Südamerika',
        'Oceania' => 'Ozeanien', 'Antarctica' => 'Antarktis'
    ];
}

/**
 * Kanonische Regionskennungen. In country.json wird kuenftig je Land
 * "region": "west_nordeuropa" (o.ae.) eingetragen.
 * Zulaessig ist alternativ der exakte deutsche Anzeigename.
 * Mischregionen werden ausschliesslich hier abgeleitet und NICHT in der JSON gespeichert.
 */
function wv_region_names() {
    return [
        'nordamerika' => 'Nordamerika',
        'lateinamerika' => 'Lateinamerika',
        'west_nordeuropa' => 'West- und Nordeuropa',
        'suedeuropa' => 'Südeuropa',
        'osteuropa' => 'Osteuropa',
        'nordafrika' => 'Nordafrika',
        'subsahara_afrika' => 'Subsahara-Afrika',
        'zentralasien' => 'Zentralasien',
        'arabien' => 'Arabien',
        'fernostasien' => 'Fernostasien',
        'suedostasien' => 'Südostasien',
        'mittlerer_osten' => 'Mittlerer Osten',
        'ozeanien' => 'Ozeanien',
        // Ueberlappende, aus den Basisregionen abgeleitete Mischregionen.
        'westen' => 'Westen',
        'ostasien' => 'Ostasien',
        'reggaeton' => 'Reggaeton',
    ];
}

/**
 * Religionsnamen sind benutzerdefinierte Laendergruppen, keine Aussage ueber
 * Religionszugehoerigkeit einzelner Personen. Mehrfachzuordnung ist moeglich.
 * Weder die Basisregionen noch country.json werden dafuer veraendert.
 */
function wv_religion_names() {
    return [
        'christentum' => 'Christentum',
        'islam' => 'Islam',
        'judentum' => 'Judentum',
        'buddhismus' => 'Buddhismus',
        'hinduismus' => 'Hinduismus',
    ];
}

function wv_parse_region($value) {
    $value = trim((string)$value);
    if ($value === '') { return null; } // bestehende JSON ohne Region funktioniert weiter
    $regions = wv_region_names();
    foreach (array_slice($regions, 0, 13, true) as $key => $name) {
        if ($value === $key || wv_normalize($value) === wv_normalize($name)) {
            return $key;
        }
    }
    throw new RuntimeException('Unbekannte Basisregion in country.json: '.$value);
}

function wv_regions_for_country($basis, $continent, $countryCode) {
    if ($basis === null) { return []; }
    $result = [$basis];
    // "Westen": Nordamerika (USA/Kanada laut Basisregion), West-/Nordeuropa, Ozeanien.
    if (in_array($basis, ['nordamerika', 'west_nordeuropa', 'ozeanien'], true)) {
        $result[] = 'westen';
    }
    if (in_array($basis, ['fernostasien', 'suedostasien'], true)) {
        $result[] = 'ostasien';
    }
    if (in_array($basis, ['lateinamerika', 'suedeuropa'], true)) {
        $result[] = 'reggaeton';
    }
    return $result;
}

function wv_religions_for_country($basis, $continent, $countryCode) {
    if ($basis === null) { return []; }
    $result = [];
    // Christentum: Amerika/Europa/Ozeanien, Russland zusaetzlich,
    // Albanien ausdruecklich ausgeschlossen.
    if ($countryCode !== 'al' && (
        in_array($continent, ['North America', 'South America', 'Europe', 'Oceania'], true)
        || $countryCode === 'ru'
    )) {
        $result[] = 'christentum';
    }
    // Islam: Nordafrika, Arabien (ohne Israel), Iran sowie Albanien.
    if ($countryCode !== 'il' && (
        in_array($basis, ['nordafrika', 'arabien'], true)
        || in_array($countryCode, ['ir', 'al'], true)
    )) {
        $result[] = 'islam';
    }
    if ($countryCode === 'il') { $result[] = 'judentum'; }
    // Grobe Laendergruppen fuer die Auswertung, keine Individuen-Merkmale.
    if (in_array($countryCode, ['bt', 'kh', 'cn', 'jp', 'la', 'mn', 'mm', 'kr', 'lk', 'th', 'tw', 'vn'], true)) {
        $result[] = 'buddhismus';
    }
    if (in_array($countryCode, ['in', 'np', 'mu'], true)) {
        $result[] = 'hinduismus';
    }
    return $result;
}

function wv_memberships($entry, $type) {
    if ($type === 'region' || $type === 'religion') { return $entry[$type] ?? []; }
    return isset($entry[$type]) && $entry[$type] !== null ? [$entry[$type]] : [];
}

function wv_normalize($value) {
    $value = trim((string)$value);
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    $value = str_replace(['ß', '&'], ['ss', ' und '], $value);
    if (function_exists('iconv')) {
        $latin = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($latin !== false) {
            $value = strtolower($latin);
        }
    }
    $value = strtr($value, [
        'ä'=>'a', 'ö'=>'o', 'ü'=>'u', 'é'=>'e', 'è'=>'e', 'ê'=>'e',
        'á'=>'a', 'à'=>'a', 'â'=>'a', 'ç'=>'c', 'í'=>'i', 'ó'=>'o',
        'ú'=>'u', 'ñ'=>'n', 'ë'=>'e', 'ï'=>'i', 'ô'=>'o', 'ã'=>'a',
    ]);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function wv_load_countries($filename) {
    if (!is_readable($filename)) {
        throw new RuntimeException('country.json ist nicht lesbar: '.$filename);
    }
    $items = json_decode((string)file_get_contents($filename), true);
    if (!is_array($items) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('country.json enthaelt kein gueltiges Array.');
    }
    $aliases = [];
    $countries = [];
    $continentNames = wv_continent_names();
    $register = static function ($name, $code) use (&$aliases) {
        $key = wv_normalize($name);
        if ($key === '') { return; }
        if (isset($aliases[$key]) && $aliases[$key] !== $code) {
            throw new RuntimeException('Mehrdeutiger Laenderalias: '.$name);
        }
        $aliases[$key] = $code;
    };
    foreach ($items as $item) {
        if (!is_array($item)) { continue; }
        $code = strtolower((string)($item['code'] ?? ''));
        if (($item['iso'] ?? false) !== true && $code !== 'xk') { continue; }
        if (!preg_match('/^[a-z]{2}$/', $code)) { continue; }
        $name = (string)($item['name'] ?? '');
        $continent = (string)($item['continent'] ?? '');
        if ($name === '' || !isset($continentNames[$continent])) { continue; }
        $basisRegion = wv_parse_region($item['region'] ?? null);
        $countries[$code] = [
            'name' => $name, 'continent' => $continent,
            'region_basis' => $basisRegion,
            'regions' => wv_regions_for_country($basisRegion, $continent, $code),
            'religions' => wv_religions_for_country($basisRegion, $continent, $code),
        ];
        $register($name, $code);
        $register($code, $code);
    }
    foreach (wv_german_aliases() as $code => $synonyms) {
        if (!isset($countries[$code])) {
            throw new RuntimeException('Deutscher Alias fuer nicht existentes Land '.$code);
        }
        $variants = explode('|', $synonyms);
        $countries[$code]['name'] = $variants[0];
        foreach ($variants as $name) { $register($name, $code); }
    }
    return [$countries, $aliases];
}

function wv_country_code($name, $aliases) {
    $key = wv_normalize($name);
    return $key !== '' ? ($aliases[$key] ?? null) : null;
}

function wv_read_applicants($filename, $countries) {
    if (!is_readable($filename)) {
        throw new RuntimeException('Bewerber-JSON nicht lesbar. Vorher read_bewerber.py ausfuehren und Dateirechte pruefen: '.$filename);
    }
    $data = json_decode((string)file_get_contents($filename), true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || !$data) {
        throw new RuntimeException('Bewerber-JSON ist leer oder ungueltig.');
    }
    $result = [];
    foreach ($data as $filename => $mapping) {
        if (!is_string($filename) || basename($filename) !== $filename ||
            !preg_match('/\.pdf$/i', $filename) || !is_array($mapping) || !$mapping) {
            throw new RuntimeException('Ungueltiger PDF-Eintrag in Bewerber-JSON');
        }
        $converted = [];
        foreach ($mapping as $number => $code) {
            $number = (string)$number;
            $code = strtolower(trim((string)$code));
            if (!ctype_digit($number) || (int)$number < 1 || !isset($countries[$code])) {
                throw new RuntimeException('Ungueltiger Kandidaten-/Laendercode bei '.$filename);
            }
            if (isset($converted[(int)$number])) {
                throw new RuntimeException('Doppelte Bewerbernummer: '.$filename);
            }
            $converted[(int)$number] = $code;
        }
        if (!$converted) {
            throw new RuntimeException('Leere Bewerberliste: '.$filename);
        }
        ksort($converted, SORT_NUMERIC);
        if (array_keys($converted) !== range(1, count($converted))) {
            throw new RuntimeException('Kandidatennummern nicht 1..N: '.$filename);
        }
        $result[$filename] = $converted;
    }
    return $result;
}

function wv_db_rows($conn, $query) {
    $res = mysqli_query($conn, $query);
    if (!$res) { throw new RuntimeException('Datenbankabfrage fehlgeschlagen.'); }
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) { $rows[] = $row; }
    mysqli_free_result($res);
    return $rows;
}

function wv_valid_polls($polls, $applicants) {
    $accepted = [];
    $total = 0;
    $saved = 0;
    foreach ($polls as $p) {
        $pid = (int)$p['id'];
        $expected = (int)$p['anzahl_kandidaten'];
        $total += max(0, $expected);
        $fn = basename(str_replace('\\', '/', (string)($p['pfad'] ?? '')));
        if ($expected <= 0 || !isset($applicants[$fn]) || count($applicants[$fn]) !== $expected) {
            continue;
        }
        if (isset($accepted[$pid])) { throw new RuntimeException('Doppelte Poll-ID'); }
        $accepted[$pid] = $applicants[$fn];
        $saved += $expected;
    }
    if (!$accepted) { throw new RuntimeException('Keine einzige Abstimmung mit vollstaendigen Bewerberdaten gefunden.'); }
    return [$accepted, $total, $saved];
}

function wv_users($users, $aliases, $countries) {
    $indexed = [];
    $known = 0;
    $unknown = 0;
    foreach ($users as $user) {
        if (!isset($user['uid']) || !is_numeric($user['uid'])) { continue; }
        $uid = (string)(int)$user['uid'];
        if (array_key_exists($uid, $indexed)) { throw new RuntimeException('Doppelte users.uid'); }
        $code = wv_country_code((string)($user['geburtsort'] ?? ''), $aliases);
        if ($code !== null && isset($countries[$code])) { $known++; }
        else { $unknown++; $code = null; }
        $indexed[$uid] = $code;
    }
    return [$indexed, $known, $unknown];
}

function wv_ballots($votes, $pollMap, $userIndex, $countries) {
    $raw = [];
    $bad = [];
    $stats = ['stimmzeilen' => 0, 'stimmzeilen_ausgeschlossen' => 0,
              'ungueltige_wahlteilnahmen' => 0, 'unbekannte_herkunft' => 0,
              'wahlteilnahmen' => 0, 'wahlteilnahmen_mit_herkunft' => 0,
              'praeferenzen' => 0];
    foreach ($votes as $v) {
        $stats['stimmzeilen']++;
        if (!isset($v['uid'], $v['pollid']) || !is_numeric($v['uid']) || !is_numeric($v['pollid'])) {
            continue;
        }
        $pid = (int)$v['pollid'];
        if (!isset($pollMap[$pid])) { $stats['stimmzeilen_ausgeschlossen']++; continue; }
        $uid = (string)(int)$v['uid'];
        $key = $uid.':'.$pid;
        if (!isset($raw[$key])) { $raw[$key] = ['uid' => $uid, 'pid' => $pid, 'picks' => []]; }
        if (!isset($v['kandidat'], $v['count']) || !is_numeric($v['kandidat']) || !is_numeric($v['count']) ||
            (string)(int)$v['kandidat'] !== (string)$v['kandidat'] ||
            (string)(int)$v['count'] !== (string)$v['count']) {
            $bad[$key] = true;
            continue;
        }
        $raw[$key]['picks'][] = [(int)$v['kandidat'], (int)$v['count']];
    }
    $byPoll = [];
    foreach ($raw as $key => $ballot) {
        $picks = $ballot['picks'];
        if (isset($bad[$key]) || count($picks) < 1 || count($picks) > 3) {
            $stats['ungueltige_wahlteilnahmen']++; continue;
        }
        $scores = []; $choices = []; $selected = [];
        $pid = $ballot['pid'];
        $valid = true;
        foreach ($picks as $pick) {
            list($candidate, $score) = $pick;
            if ($score < 1 || $score > 3 || isset($scores[$score]) ||
                isset($choices[$candidate]) || !isset($pollMap[$pid][$candidate])) {
                $valid = false; break;
            }
            $scores[$score] = true; $choices[$candidate] = true;
            $country = $pollMap[$pid][$candidate];
            $selected[] = [
                'land' => $country, 'kontinent' => $countries[$country]['continent'],
                'region' => $countries[$country]['regions'],
                'religion' => $countries[$country]['religions'], 'punkte' => $score,
            ];
        }
        if (!$valid) { $stats['ungueltige_wahlteilnahmen']++; continue; }
        $uid = $ballot['uid'];
        $land = $userIndex[$uid] ?? null;
        $kontinent = $land !== null ? $countries[$land]['continent'] : null;
        $region = $land !== null ? $countries[$land]['regions'] : [];
        $religion = $land !== null ? $countries[$land]['religions'] : [];
        if ($land === null) { $stats['unbekannte_herkunft']++; }
        else { $stats['wahlteilnahmen_mit_herkunft']++; }
        $byPoll[$pid][] = ['uid' => $uid, 'land' => $land, 'kontinent' => $kontinent, 'region' => $region, 'religion' => $religion, 'picks' => $selected];
        $stats['wahlteilnahmen']++; $stats['praeferenzen'] += count($selected);
    }
    return [$byPoll, $stats];
}

function wv_ballot_targets($ballot, $target) {
    $hits = [];
    $points = [];
    $sum = 0;
    foreach ($ballot['picks'] as $p) { $sum += $p['punkte']; }
    foreach ($ballot['picks'] as $p) {
        foreach (wv_memberships($p, $target) as $name) {
            $hits[$name] = true;
            if (!isset($points[$name])) { $points[$name] = 0.0; }
            $points[$name] += $sum > 0 ? $p['punkte'] / $sum : 0;
        }
    }
    return [array_keys($hits), $points];
}

function wv_aggregate($byPoll, $sourceType, $targetType, $minUsers, $pollMap = null, $countries = null) {
    $groups = [];
    foreach ($byPoll as $ballots) {
        foreach ($ballots as $b) {
            foreach (wv_memberships($b, $sourceType) as $group) {
                $groups[$group][$b['uid']] = true;
            }
        }
    }
    $results = [];
    foreach ($groups as $group => $userSet) {
        if (count($userSet) < $minUsers) { continue; }
        $matchedUsers = [];
        $restUsers = [];
        $matchedPolls = 0;
        $ngTotal = 0;
        $nrTotal = 0;
        $acc = [];
        // Ausschliesslich Laender aus Polls mit vergleichbaren Waehlergruppen.
        $sourceCountryFlags = [];
        $regionCandidateFlags = [];
        foreach ($byPoll as $pollId => $ballots) {
            $selected = []; $rest = [];
            foreach ($ballots as $b) {
                if (in_array($group, wv_memberships($b, $sourceType), true)) { $selected[] = $b; }
                else { $rest[] = $b; }
            }
            if (!$selected || !$rest) { continue; }
            $matchedPolls++;
            $ng = count($selected); $nr = count($rest);
            $ngTotal += $ng; $nrTotal += $nr;
            $groupHits = []; $restHits = []; $groupPoints = []; $restPoints = [];
            foreach ($selected as $b) {
                $matchedUsers[$b['uid']] = true;
                if (!empty($b['land'])) { $sourceCountryFlags[$b['land']] = true; }
                list($targets, $points) = wv_ballot_targets($b, $targetType);
                foreach ($targets as $t) { $groupHits[$t] = ($groupHits[$t] ?? 0) + 1; }
                foreach ($points as $t => $val) { $groupPoints[$t] = ($groupPoints[$t] ?? 0.0) + $val; }
            }
            foreach ($rest as $b) {
                $restUsers[$b['uid']] = true;
                list($targets, $points) = wv_ballot_targets($b, $targetType);
                foreach ($targets as $t) { $restHits[$t] = ($restHits[$t] ?? 0) + 1; }
                foreach ($points as $t => $val) { $restPoints[$t] = ($restPoints[$t] ?? 0.0) + $val; }
            }
            $allTargets = array_unique(array_merge(
                array_keys($groupHits), array_keys($restHits),
                array_keys($groupPoints), array_keys($restPoints)
            ));
            // Auch Ziele mit null Stimmen anzeigen, sofern Kandidaten aus diesem
            // Land/Kontinent bei einer vergleichbaren Abstimmung antraten.
            // Insbesondere fuer die umgekehrte Ansicht notwendig.
            if (is_array($pollMap) && isset($pollMap[$pollId]) && is_array($countries)) {
                foreach ($pollMap[$pollId] as $candidateCode) {
                    if ($targetType === 'land') {
                        $allTargets[] = $candidateCode;
                    } elseif ($targetType === 'kontinent' && isset($countries[$candidateCode]['continent'])) {
                        $continentCode = $countries[$candidateCode]['continent'];
                        $allTargets[] = $continentCode;
                        $regionCandidateFlags[$continentCode][$candidateCode] = true;
                    } elseif (in_array($targetType, ['region', 'religion'], true) && isset($countries[$candidateCode])) {
                        foreach ($countries[$candidateCode][$targetType === 'region' ? 'regions' : 'religions'] as $regionCode) {
                            $allTargets[] = $regionCode;
                            $regionCandidateFlags[$regionCode][$candidateCode] = true;
                        }
                    }
                }
                $allTargets = array_unique($allTargets);
            }
            foreach ($allTargets as $t) {
                if (!isset($acc[$t])) { $acc[$t] = [0, 0.0, 0.0, 0.0]; }
                $acc[$t][0] += $groupHits[$t] ?? 0;
                $acc[$t][1] += $ng * ($restHits[$t] ?? 0) / $nr;
                $acc[$t][2] += $groupPoints[$t] ?? 0.0;
                $acc[$t][3] += $ng * ($restPoints[$t] ?? 0.0) / $nr;
            }
        }
        // WICHTIG: Mindestgrenze nur fuer die analysierte Gruppe, nicht Rest oder Ziel.
        if ($ngTotal === 0 || count($matchedUsers) < $minUsers) { continue; }
        foreach ($acc as $t => $cell) {
            if (!$cell[0] && !$cell[1] && !is_array($pollMap)) { continue; }
            $hitGroup = 100 * $cell[0] / $ngTotal;
            $hitRest = 100 * $cell[1] / $ngTotal;
            $pointsGroup = 100 * $cell[2] / $ngTotal;
            $pointsRest = 100 * $cell[3] / $ngTotal;
            $results[] = [
                'waehler_gruppe' => $group, 'bewerber_ziel' => $t,
                'waehler_laender' => array_keys($sourceCountryFlags),
                'ziel_laender' => in_array($targetType, ['kontinent', 'region', 'religion'], true) ? array_keys($regionCandidateFlags[$t] ?? []) : [],
                'waehler_einmalig' => count($matchedUsers),
                'waehler_einmalig_rest' => count($restUsers),
                'waehler_einmalig_insgesamt' => count($userSet),
                'wahlteilnahmen_gruppe' => $ngTotal, 'wahlteilnahmen_rest' => $nrTotal,
                'abstimmungen_vergleichbar' => $matchedPolls,
                'treffer_gruppe' => $cell[0],
                'trefferquote_gruppe_pct' => round($hitGroup, 4),
                'trefferquote_rest_pct' => round($hitRest, 4),
                'differenz_prozentpunkte' => round($hitGroup - $hitRest, 4),
                'punkteanteil_gruppe_pct' => round($pointsGroup, 4),
                'punkteanteil_rest_pct' => round($pointsRest, 4),
                'punkte_differenz_prozentpunkte' => round($pointsGroup - $pointsRest, 4),
            ];
        }
    }
    return $results;
}

/**
 * Gesamtansicht "Alle": Auswahlwahrscheinlichkeit je einzelner Bewerberposition,
 * bereinigt um unterschiedliche Bewerberzahlen und Wahlbeteiligung je Abstimmung.
 *
 * Pro Abstimmung p mit N Bewerbern, B gueltigen Wahlteilnahmen und V vergebenen
 * Praeferenzen betraegt die erwartete Trefferquote je Bewerber V / (N * B).
 * Fuer eine Nationalitaet werden alle Bewerber-Wahlteilnahme-Chancen addiert:
 *   Ist-Quote = 100 * erhaltene Praeferenzen / Summe(B * Kandidaten dieser Nation)
 *   Erwartung  = 100 * Summe(V * Kandidaten dieser Nation / N) / denselben Nenner
 * Die Differenz in Prozentpunkten hat einen echten Nullpunkt (Gleichverteilung
 * innerhalb jeder Abstimmung); die Bewerberzahl einer Nation verzerrt sie nicht.
 *
 * Punkte-Modus: Der Anteil der 3/2/1-Punkte wird pro Wahlteilnahme auf 1
 * normiert. Erwartungswert je Bewerber ist dann 1/N statt V/(N*B).
 * Waehlherkunft ist hier irrelevant: ALLE gueltigen Wahlteilnahmen zaehlen.
 */
function wv_aggregate_all($byPoll, $targetType, $pollMap, $countries) {
    $ballotCount = 0;
    $uniqueUsers = [];
    $acc = [];

    foreach ($byPoll as $pollId => $ballots) {
        $candidates = $pollMap[$pollId] ?? [];
        $n = count($candidates);
        $b = count($ballots);
        if ($n === 0 || $b === 0) { continue; }
        $ballotCount += $b;

        // Jede Bewerberposition zaehlt einmal – auch bei null Stimmen.
        $candidateNumbers = [];
        foreach ($candidates as $countryCode) {
            $target = $targetType === 'land' ? $countryCode : $countries[$countryCode]['continent'];
            $candidateNumbers[$target] = ($candidateNumbers[$target] ?? 0) + 1;
        }

        $hits = [];
        $points = [];
        $preferences = 0;
        foreach ($ballots as $ballot) {
            $uniqueUsers[$ballot['uid']] = true;
            $scoreSum = 0;
            foreach ($ballot['picks'] as $pick) { $scoreSum += $pick['punkte']; }
            foreach ($ballot['picks'] as $pick) {
                $preferences++;
                $target = $targetType === 'land' ? $pick['land'] : $pick['kontinent'];
                $hits[$target] = ($hits[$target] ?? 0) + 1;
                $points[$target] = ($points[$target] ?? 0.0) + ($scoreSum > 0 ? $pick['punkte'] / $scoreSum : 0.0);
            }
        }
        foreach ($candidateNumbers as $target => $numCandidates) {
            if (!isset($acc[$target])) {
                // Tats. Treffer, erwartete Treffer, tats. Punkte, erwartete Punkte,
                // Bewerber-Wahlteilnahme-Chancen, Bewerberpositionen.
                $acc[$target] = [0.0, 0.0, 0.0, 0.0, 0, 0];
            }
            $acc[$target][0] += $hits[$target] ?? 0;
            $acc[$target][1] += $numCandidates * $preferences / $n;
            $acc[$target][2] += $points[$target] ?? 0.0;
            $acc[$target][3] += $numCandidates * $b / $n;
            $acc[$target][4] += $numCandidates * $b;
            $acc[$target][5] += $numCandidates;
        }
    }
    if ($ballotCount === 0) { return []; }
    $result = [];
    foreach ($acc as $target => $cell) {
        list($hitCount, $expectedHits, $score, $expectedScore, $opportunities, $candidacies) = $cell;
        if ($opportunities <= 0) { continue; }
        $actualRate = 100 * $hitCount / $opportunities;
        $expectedRate = 100 * $expectedHits / $opportunities;
        $actualPoints = 100 * $score / $opportunities;
        $expectedPoints = 100 * $expectedScore / $opportunities;
        $result[] = [
            'waehler_gruppe' => '__alle__', 'bewerber_ziel' => $target,
            'waehler_einmalig' => count($uniqueUsers),
            'waehler_einmalig_insgesamt' => count($uniqueUsers),
            'wahlteilnahmen_gruppe' => $ballotCount,
            'treffer_gruppe' => $hitCount,
            'bewerber_chancen' => $opportunities,
            'bewerber_positionen' => $candidacies,
            'trefferquote_gruppe_pct' => round($actualRate, 4),
            'trefferquote_rest_pct' => round($expectedRate, 4),
            'differenz_prozentpunkte' => round($actualRate - $expectedRate, 4),
            'punkteanteil_gruppe_pct' => round($actualPoints, 4),
            'punkteanteil_rest_pct' => round($expectedPoints, 4),
            'punkte_differenz_prozentpunkte' => round($actualPoints - $expectedPoints, 4),
        ];
    }
    return $result;
}

function wv_country_flag_maps($byPoll, $pollMaps, $countries) {
    // Tatsaechlich vorhandene Laender fuer die Flaggen oberhalb des Diagramms.
    // WAEHLER: Nur gueltige Stimmen mit erkannter Herkunft in Abstimmungen,
    // in denen mindestens ein Vergleichswaehler ausserhalb der Gruppe existiert.
    // BEWERBER: Nur Nationalitaeten, die wirklich in beruecksichtigten PDFs stehen.
    // Beide Listen enthalten ausschliesslich aggregierte ISO-Codes, keine UIDs.
    $sourceCountryFlags = ['land' => [], 'kontinent' => [], 'region' => [], 'religion' => []];
    foreach (['land', 'kontinent', 'region', 'religion'] as $dimension) {
        foreach ($byPoll as $ballots) {
            $groupSizes = [];
            foreach ($ballots as $ballot) {
                foreach (wv_memberships($ballot, $dimension) as $group) {
                    $groupSizes[$group] = ($groupSizes[$group] ?? 0) + 1;
                }
            }
            $pollSize = count($ballots);
            foreach ($ballots as $ballot) {
                if ($ballot['land'] === null) { continue; }
                foreach (wv_memberships($ballot, $dimension) as $group) {
                    if ($groupSizes[$group] < $pollSize) {
                        $sourceCountryFlags[$dimension][$group][$ballot['land']] = true;
                    }
                }
            }
        }
    }
    $candidateCountryFlags = ['land' => [], 'kontinent' => [], 'region' => [], 'religion' => []];
    foreach ($pollMaps as $candidateList) {
        foreach ($candidateList as $countryCode) {
            $countryEntry = $countries[$countryCode];
            $candidateCountryFlags['land'][$countryCode][$countryCode] = true;
            $candidateCountryFlags['kontinent'][$countryEntry['continent']][$countryCode] = true;
            foreach ($countryEntry['regions'] as $regionCode) {
                $candidateCountryFlags['region'][$regionCode][$countryCode] = true;
            }
            foreach ($countryEntry['religions'] as $religionCode) {
                $candidateCountryFlags['religion'][$religionCode][$countryCode] = true;
            }
        }
    }
    foreach (['land', 'kontinent', 'region', 'religion'] as $dimension) {
        foreach ($sourceCountryFlags[$dimension] as $group => $set) {
            $codes = array_keys($set); sort($codes, SORT_STRING);
            $sourceCountryFlags[$dimension][$group] = $codes;
        }
        foreach ($candidateCountryFlags[$dimension] as $group => $set) {
            $codes = array_keys($set); sort($codes, SORT_STRING);
            $candidateCountryFlags[$dimension][$group] = $codes;
        }
    }
    return [$sourceCountryFlags, $candidateCountryFlags];
}

function wv_compute($conn, $root) {
    list($countries, $aliases) = wv_load_countries($root.'/PHP/flag-icons/country.json');
    $applicants = wv_read_applicants($root.'/skripte/bewerber_nationalitaeten.json', $countries);
    $polls = wv_db_rows($conn, 'SELECT id, pfad, anzahl_kandidaten FROM bapolls');
    $users = wv_db_rows($conn, 'SELECT uid, geburtsort FROM users');
    $votes = wv_db_rows($conn, 'SELECT uid, pollid, kandidat, count FROM bavotes');
    list($pollMaps, $total, $included) = wv_valid_polls($polls, $applicants);
    list($userIndex, $known, $unknown) = wv_users($users, $aliases, $countries);
    list($byPoll, $voteStats) = wv_ballots($votes, $pollMaps, $userIndex, $countries);
    if (!$byPoll) { throw new RuntimeException('Keine gueltigen Wahlteilnahmen gefunden.'); }
    // Nur unterschiedliche Personen, die tatsaechlich mindestens einmal gueltig
    // abgestimmt haben; keine bloss registrierten users und keine Doppeltzaehlung.
    $voterSets = [];
    foreach ($byPoll as $ballots) {
        foreach ($ballots as $ballot) {
            if ($ballot['land'] !== null) {
                $voterSets[$ballot['land']][$ballot['uid']] = true;
            }
        }
    }
    $voterCounts = [];
    foreach ($voterSets as $code => $set) { $voterCounts[$code] = count($set); }

    $matrices = [];
    // Nur Nationalitaet und Kontinent direkt ueber Waehlereintraege aggregieren.
    // WAEHLERREGIONEN: Sie werden im Browser dynamisch aus den separat berechneten
    // Nationalitaetswerten gemittelt (jedes Herkunftsland gleiches Gewicht).
    // Dadurch gilt das einstellbare Waehlerminimum pro Land vor der Mittelung,
    // auch fuer ueberlappende Mischregionen. Kein Pooling aller Regionswaehler.
    foreach (['land', 'kontinent'] as $source) {
        foreach (['land', 'kontinent', 'region', 'religion'] as $target) {
            $matrices[$source.'_zu_'.$target] = wv_aggregate($byPoll, $source, $target, 1, $pollMaps, $countries);
        }
    }
    // Die Gesamtansicht beruecksichtigt ALLE gueltigen Wahlteilnahmen,
    // auch unbekannte Herkunft. Referenz: gleichverteilte Stimmen pro
    // Bewerberposition innerhalb derselben Abstimmung (statt andere Waehler).
    $allMatrices = [];
    foreach (['land', 'kontinent'] as $target) {
        $allMatrices[$target] = wv_aggregate_all($byPoll, $target, $pollMaps, $countries);
    }
    // Anzahl der Bewerbungen pro Nationalitaet/Kontinent in auswertbaren PDFs.
    // Dieselbe Person kann in mehreren Abstimmungen kandidiert haben; eindeutige
    // Personen sind anhand der Bewerber-JSON nicht ermittelbar.
    $candidateCounts = ['land' => [], 'kontinent' => [], 'region' => [], 'religion' => []];
    foreach ($pollMaps as $candidateList) {
        foreach ($candidateList as $countryCode) {
            $continentCode = $countries[$countryCode]['continent'];
            $candidateCounts['land'][$countryCode] = ($candidateCounts['land'][$countryCode] ?? 0) + 1;
            $candidateCounts['kontinent'][$continentCode] = ($candidateCounts['kontinent'][$continentCode] ?? 0) + 1;
            foreach ($countries[$countryCode]['regions'] as $regionCode) {
                $candidateCounts['region'][$regionCode] = ($candidateCounts['region'][$regionCode] ?? 0) + 1;
            }
            foreach ($countries[$countryCode]['religions'] as $religionCode) {
                $candidateCounts['religion'][$religionCode] = ($candidateCounts['religion'][$religionCode] ?? 0) + 1;
            }
        }
    }
    list($sourceCountryFlags, $candidateCountryFlags) = wv_country_flag_maps($byPoll, $pollMaps, $countries);
    $allCountryFlags = [];
    foreach ($byPoll as $ballots) {
        foreach ($ballots as $ballot) {
            if ($ballot['land'] !== null) { $allCountryFlags[$ballot['land']] = true; }
        }
    }
    $allCountryFlags = array_keys($allCountryFlags);
    sort($allCountryFlags, SORT_STRING);
    $usedGroups = [];
    foreach (['land', 'kontinent'] as $source) {
        $key = $source.'_zu_land';
        $usedGroups[$source] = count(array_unique(array_column($matrices[$key], 'waehler_gruppe')));
    }
    return [
        'countries' => $countries, 'continents' => wv_continent_names(),
        'regions' => wv_region_names(), 'religions' => wv_religion_names(),
        'matrices' => $matrices, 'all_matrices' => $allMatrices, 'candidate_counts' => $candidateCounts,
        'voter_counts' => $voterCounts,
        'all_country_flags' => $allCountryFlags,
        'source_country_flags' => $sourceCountryFlags,
        'candidate_country_flags' => $candidateCountryFlags,
        'quality' => [
            'polls' => count($polls), 'polls_included' => count($pollMaps),
            'polls_excluded' => count($polls) - count($pollMaps),
            'candidates' => $total, 'candidates_included' => $included,
            'coverage' => $total > 0 ? round(100 * $included / $total, 2) : 0,
            'users_known' => $known, 'users_unknown' => $unknown,
            'ballots' => $voteStats['wahlteilnahmen'],
            'ballots_known' => $voteStats['wahlteilnahmen_mit_herkunft'],
            'vote_rows' => $voteStats['stimmzeilen'],
            'vote_rows_excluded' => $voteStats['stimmzeilen_ausgeschlossen'],
            'invalid_ballots' => $voteStats['ungueltige_wahlteilnahmen'],
            'preferences' => $voteStats['praeferenzen'],
            'groups_country' => $usedGroups['land'],
            'groups_continent' => $usedGroups['kontinent'],
        ],
        'json_updated' => @filemtime($root.'/skripte/bewerber_nationalitaeten.json') ?: null,
    ];
}

function wv_refresh_json($root) {
    $script = $root.'/skripte/read_bewerber.py';
    $json = $root.'/skripte/bewerber_nationalitaeten.json';
    if (!is_file($script)) {
        return ['ok' => false, 'message' => 'read_bewerber.py nicht gefunden.'];
    }
    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'PHP-Funktion exec() ist deaktiviert.'];
    }
    // Nur fixe, serverseitige Befehle; keine vom Benutzer uebernommenen Parameter.
    // sudoers MUSS genau diese beiden Aktionen beschraenken (Anleitung unten).
    $command = '/usr/bin/sudo -n /usr/bin/python3 '.escapeshellarg($script).' 2>&1';
    $output = []; $code = 127;
    @set_time_limit(300);
    @exec($command, $output, $code);
    $outputText = implode("\n", array_slice($output, -40));
    $written = is_file($json) && strpos(implode("\n", $output), 'JSON geschrieben:') !== false;
    if (!in_array($code, [0, 2], true) || !$written) {
        return ['ok' => false, 'message' => 'Python-Ausfuehrung fehlgeschlagen (Exit '.$code.'). '.mb_substr_safe($outputText, 1100)];
    }
    // read_bewerber.py erzeugt bewusst ein neues 0600-File per atomic rename.
    // Ausschliesslich die fertige JSON fuer PHP lesbar machen (Datei ist ausserhalb der Webroot).
    $permissionOutput = []; $permissionCode = 127;
    @exec('/usr/bin/sudo -n /usr/bin/chmod 0644 '.escapeshellarg($json).' 2>&1', $permissionOutput, $permissionCode);
    if ($permissionCode !== 0) {
        return ['ok' => false, 'message' => 'JSON wurde aktualisiert, aber PHP kann die Dateirechte nicht setzen. sudoers/chmod pruefen. '.mb_substr_safe(implode(' ', $permissionOutput), 400)];
    }
    return [
        'ok' => true,
        'message' => $code === 2
            ? 'Bewerber-JSON erneuert (Teilabdeckung: problematische PDFs wurden ausgelassen).'
            : 'Bewerber-JSON vollstaendig aktualisiert.',
    ];
}

function mb_substr_safe($text, $limit) {
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}

function wv_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * PDF-Gesamtbericht mit FPDF (A4-Hochformat).
 * Der Browser liefert dieselben statistischen Auswertungen wie die Webansicht
 * und rastert die lokal eingebundenen Länderflaggen einmalig als kleine PNGs.
 * PDF-Layout, Diagramme, Tabellen und Inhaltsverzeichnis entstehen in PHP.
 */
function wv_create_report_pdf(array $report) {
    $fpdfPath = __DIR__.'/FPDF/fpdf.php';
    if (!is_readable($fpdfPath)) {
        throw new RuntimeException('FPDF nicht gefunden: /WEH/PHP/FPDF/fpdf.php');
    }
    require_once $fpdfPath;
    if (!class_exists('FPDF')) {
        throw new RuntimeException('FPDF konnte nicht geladen werden.');
    }

    // FPDF kann PNGs direkt einbetten, jedoch keine SVG-Dateien aus flag-icons.
    // Der Browser konvertiert deshalb nur für diesen Export SVG -> PNG.
    $flagFiles = [];
    try {
        foreach (($report['flags'] ?? []) as $code => $encoded) {
            if (!is_string($code) || !preg_match('/^[a-z]{2}$/', $code) || !is_string($encoded) ||
                strlen($encoded) > 55000 || !str_starts_with($encoded, 'data:image/png;base64,')) {
                continue;
            }
            $bytes = base64_decode(substr($encoded, 22), true);
            if ($bytes === false || strlen($bytes) < 50 || strlen($bytes) > 40000 ||
                substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'wvflag_');
            if ($file === false) { continue; }
            if (file_put_contents($file, $bytes) === false) { @unlink($file); continue; }
            $flagFiles[$code] = $file;
        }

        $pdf = new class($flagFiles) extends FPDF {
            private $flags;
            private $tocPages = [];
            private $actualToc = [];
            private $tocLinks = [];  // Interne PDF-Ziele fuer anklickbares Inhaltsverzeichnis.
            private $currentChapter = '';
            private $currentSection = '';
            private $created;
            private $left = 12.0;
            private $right = 198.0;
            private $width = 186.0;
            private $bottom = 279.0;
            private $cover = false;
            private $toc = false;
            private $chapterPending = false;

            public function __construct(array $flags) {
                parent::__construct('P', 'mm', 'A4');
                $this->flags = $flags;
                $this->created = date('d.m.Y H:i');
                $this->SetMargins(12, 12, 12);
                $this->SetAutoPageBreak(false);
                $this->SetCompression(true);
                $this->SetTitle($this->txt('WEH - BA-Wahlverhalten: Gesamtanalyse'));
                $this->SetAuthor('WEH e.V.');
                $this->AliasNbPages();
            }

            private function txt($value) {
                $s = (string)($value ?? '');
                $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? $s;
                if (function_exists('iconv')) {
                    $v = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
                    if ($v !== false) { return $v; }
                }
                if (function_exists('mb_convert_encoding')) {
                    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
                }
                return preg_replace('/[^\x20-\x7E]/', '?', $s);
            }

            private function truncate($value, $maxWidth) {
                $s = $this->txt($value);
                if ($this->GetStringWidth($s) <= $maxWidth) { return $s; }
                while (strlen($s) && $this->GetStringWidth($s.'...') > $maxWidth) {
                    $s = substr($s, 0, -1);
                }
                return $s.'...';
            }

            public function Header() {
                $this->SetFillColor(17, 165, 13);
                $this->Rect(0, 0, 210, 2.2, 'F');
                if ($this->cover) { return; }
                $this->SetXY(12, 6);
                $this->SetFont('Arial', 'B', 8);
                $this->SetTextColor(24, 113, 35);
                $this->Cell(113, 5, 'WEH e.V.  /  BA-WAHLVERHALTEN', 0, 0, 'L');
                $this->SetFont('Arial', '', 7.5);
                $this->SetTextColor(105, 115, 106);
                $this->Cell(73, 5, $this->created, 0, 0, 'R');
                $this->SetDrawColor(207, 221, 210);
                $this->Line(12, 14, 198, 14);
                $this->SetXY(12, 17);
                $this->SetFont('Arial', 'B', 12);
                $this->SetTextColor(28, 49, 34);
                $this->Cell(186, 7, $this->truncate($this->currentSection ?: $this->currentChapter ?: 'Inhaltsverzeichnis', 185), 0, 0, 'L');
                $this->SetY(29);
            }

            public function Footer() {
                if ($this->PageNo() === 1) { return; }
                $this->SetDrawColor(207, 221, 210);
                $this->Line(12, 285, 198, 285);
                $this->SetTextColor(115, 122, 116);
                $this->SetFont('Arial', '', 7);
                $this->SetXY(12, 287);
                $this->Cell(149, 5, $this->truncate($this->currentChapter, 146), 0, 0);
                $this->Cell(37, 5, 'Seite '.$this->PageNo().'/{nb}', 0, 0, 'R');
            }

            public function setTocNumbers(array $numbers) { $this->tocPages = $numbers; }
            public function getTocNumbers() { return $this->actualToc; }

            private function start($id, $title, $level = 1) {
                $label = $id.'  '.$title;
                if ($level === 1) { $this->currentChapter = $label; }
                $this->currentSection = $label;
                $this->cover = false;
                $this->toc = false;
                // Das erste Unterkapitel beginnt auf der Kapitelanfangsseite.
                // Damit entstehen keine fast leeren Kapitel-Trennseiten.
                $samePage = $level > 1 && $this->chapterPending && $this->GetY() < 85;
                if (!$samePage) { $this->AddPage(); $this->SetY(31); }
                else { $this->Ln(6); }
                $this->chapterPending = $level === 1;
                $this->actualToc[$id] = $this->PageNo();
                if (isset($this->tocLinks[$id])) {
                    $this->SetLink($this->tocLinks[$id], $this->GetY(), $this->PageNo());
                }
                $this->SetFont('Arial', 'B', $level === 1 ? 15 : 11.5);
                $this->SetTextColor(22, 111, 35);
                $this->Cell(186, $level === 1 ? 11 : 8, $this->truncate($label, 185), 0, 1);
                $this->Ln(2);
            }

            private function room($height) {
                if ($this->GetY() + $height > $this->bottom) { $this->AddPage(); }
            }

            private function para($s, $size = 8.7, $gap = 3) {
                $this->SetFont('Arial', '', $size);
                $this->SetTextColor(75, 87, 78);
                $this->SetX(12);
                $lines = $this->wrap($s, 184);
                foreach ($lines as $line) {
                    $this->room(4.5);
                    $this->SetX(12);
                    $this->Cell(186, 4.5, $line, 0, 1);
                }
                $this->room($gap);
                $this->Ln($gap);
            }

            private function wrap($s, $width) {
                $s = $this->txt($s);
                $out = [];
                foreach (explode("\n", str_replace("\r", '', $s)) as $paragraph) {
                    $line = '';
                    foreach (preg_split('/\s+/', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $trial = $line === '' ? $word : $line.' '.$word;
                        if ($this->GetStringWidth($trial) <= $width) { $line = $trial; continue; }
                        if ($line !== '') { $out[] = $line; $line = ''; }
                        while ($this->GetStringWidth($word) > $width && strlen($word) > 1) {
                            $part = '';
                            while (strlen($word) && $this->GetStringWidth($part.$word[0]) < $width) {
                                $part .= $word[0]; $word = substr($word, 1);
                            }
                            if ($part === '') { $part = $word[0]; $word = substr($word, 1); }
                            $out[] = $part;
                        }
                        $line = $word;
                    }
                    if ($line !== '' || !$out) { $out[] = $line; }
                }
                return $out;
            }

            private function note($title, $value) {
                $this->room(13);
                $y = $this->GetY();
                $this->SetFillColor(239, 247, 239);
                $this->Rect(12, $y, 186, 11, 'F');
                $this->SetXY(15, $y + 2);
                $this->SetFont('Arial', '', 9);
                $this->SetTextColor(69, 86, 69);
                $this->Cell(123, 6, $this->truncate($title, 120), 0, 0);
                $this->SetFont('Arial', 'B', 9);
                $this->SetTextColor(25, 110, 35);
                $this->Cell(56, 6, $this->txt($value), 0, 0, 'R');
                $this->SetY($y+13);
            }

            private function flag($code, $x, $y, $w = 5.3, $h = 3.7) {
                $code = strtolower((string)$code);
                if (isset($this->flags[$code])) {
                    try {
                        $this->Image($this->flags[$code], $x, $y, $w, $h, 'PNG');
                        return;
                    } catch (Throwable $e) {
                        // Fehlerhafte einzelne Grafik darf Gesamtbericht nicht abbrechen.
                    }
                }
                if (preg_match('/^[a-z]{2}$/', $code)) {
                    $this->SetFillColor(230, 238, 232);
                    $this->Rect($x, $y, $w, $h, 'F');
                    $this->SetFont('Arial', 'B', 5);
                    $this->SetTextColor(84, 106, 91);
                    $this->SetXY($x, $y+.1);
                    $this->Cell($w, $h-.1, strtoupper($code), 0, 0, 'C');
                }
            }

            private function number($v) { return number_format((float)$v, 0, ',', '.'); }
            private function pct($v) { return number_format((float)$v, 2, ',', '.').' %'; }
            private function pp($v) {
                $v = (float)$v;
                return ($v > 0 ? '+' : '').number_format($v, 2, ',', '.').' pp';
            }

            private function dividerTitle($name) {
                $this->room(9);
                $this->Ln(1.5);
                $this->SetFont('Arial','B',9.5);
                $this->SetTextColor(31, 115, 45);
                $this->SetX(12);
                $this->Cell(186, 7, $this->truncate($name, 184), 0, 1);
            }

            private function table(array $titles, array $rows, array $widths, $flagColumn = -1, $size = 8.2) {
                if (abs(array_sum($widths)-186.0) > .15) {
                    throw new RuntimeException('Ungültige PDF-Tabellenbreite.');
                }
                $drawHeader = function() use ($titles, $widths) {
                    $this->room(8);
                    $y = $this->GetY();
                    $this->SetFillColor(29, 118, 46);
                    $this->Rect(12, $y, 186, 7.5, 'F');
                    $this->SetTextColor(255,255,255);
                    $this->SetFont('Arial','B',7.7);
                    $x = 12;
                    foreach ($titles as $i => $title) {
                        $this->SetXY($x+1.6,$y+1.1);
                        $this->Cell($widths[$i]-3,5.3,$this->truncate($title,$widths[$i]-3.5),0,0);
                        $x += $widths[$i];
                    }
                    $this->SetY($y+7.5);
                };
                $drawHeader();
                foreach ($rows as $i => $row) {
                    if ($this->GetY()+6.8 > $this->bottom) {
                        $this->AddPage(); $drawHeader();
                    }
                    $y = $this->GetY();
                    if ($i % 2 === 0) {
                        $this->SetFillColor(243, 249, 244);
                        $this->Rect(12, $y, 186, 6.2, 'F');
                    }
                    $this->SetFont('Arial','',$size);
                    $x = 12;
                    foreach ($widths as $c => $w) {
                        $item = $row[$c] ?? '';
                        $code = is_array($item) ? ($item['code'] ?? '') : '';
                        $value = is_array($item) ? ($item['value'] ?? '') : $item;
                        $delta = is_array($item) && array_key_exists('delta',$item) ? (float)$item['delta'] : null;
                        if ($delta !== null && $delta > .00001) { $this->SetTextColor(14, 143, 39); }
                        elseif ($delta !== null && $delta < -.00001) { $this->SetTextColor(194, 49, 72); }
                        else { $this->SetTextColor(36, 52, 39); }
                        $offset = $flagColumn === $c && $code ? 7.4 : 0;
                        if ($offset) { $this->flag($code, $x+1.5, $y+1.25); }
                        $this->SetXY($x+1.6+$offset, $y+.6);
                        $this->Cell($w-3.2-$offset, 5.1, $this->truncate($value,$w-3.5-$offset),0,0);
                        $x += $w;
                    }
                    $this->SetDrawColor(227,235,228);
                    $this->Line(12, $y+6.2, 198, $y+6.2);
                    $this->SetY($y+6.2);
                }
                $this->Ln(3);
            }

            // Ein quantitatives Balkendiagramm mit gemeinsamer Nulllinie.
            // Positive Abweichungen grün, negative rot; Werte in Prozentpunkten.
            private function chart(array $rows, $nameField = 'name', $deltaField = 'delta', $codeField = 'code') {
                $max = 0.5;
                foreach ($rows as $row) { $max = max($max, abs((float)($row[$deltaField] ?? 0))); }
                $this->room(14);
                $this->SetFont('Arial','',7);
                $this->SetTextColor(99, 110, 101);
                $this->SetX(12);
                $this->Cell(89, 6, 'BEWERBERGRUPPE',0,0);
                $this->Cell(53, 6, 'ABWEICHUNG ZU 0 pp',0,0,'C');
                $this->Cell(44, 6, 'DIFFERENZ',0,1,'R');
                foreach ($rows as $i => $row) {
                    $h = 7.6;
                    $this->room($h+.5);
                    $y = $this->GetY();
                    if ($i % 2 === 0) {
                        $this->SetFillColor(244,248,244); $this->Rect(12,$y,186,$h,'F');
                    }
                    $this->flag($row[$codeField] ?? '',13,$y+1.95,5.4,3.7);
                    $this->SetFont('Arial','',8.5);
                    $this->SetTextColor(35,52,38);
                    $this->SetXY(21,$y+1.15);
                    $this->Cell(77,5.6,$this->truncate($row[$nameField] ?? '',75),0,0);
                    $zeroX = 131.5; $half = 25;
                    $this->SetDrawColor(144,158,148);
                    $this->Line($zeroX,$y+.85,$zeroX,$y+$h-.85);
                    $d = (float)($row[$deltaField] ?? 0);
                    $len = min($half,abs($d)/$max*$half);
                    if ($d > 0) { $this->SetFillColor(24,165,65); $this->Rect($zeroX,$y+2.35,$len,3,'F'); }
                    elseif ($d < 0) { $this->SetFillColor(226,104,122); $this->Rect($zeroX-$len,$y+2.35,$len,3,'F'); }
                    $this->SetFont('Arial','B',8.2);
                    if ($d > 0) { $this->SetTextColor(22,135,45); }
                    elseif ($d < 0) { $this->SetTextColor(185,54,73); }
                    else { $this->SetTextColor(88,100,90); }
                    $this->SetXY(161,$y+1.2);
                    $this->Cell(36,5.2,$this->txt($this->pp($d)),0,0,'R');
                    $this->SetY($y+$h);
                }
                $this->Ln(4);
            }

            // Beide Auswertungen sind voneinander unabhängige vollständige Rankings:
            // getrennte Überschriften, Hintergrundflächen, Spalten und Balkenskalen.
            private function paired(array $group) {
                $left = is_array($group['left'] ?? null) ? $group['left'] : [];
                $right = is_array($group['right'] ?? null) ? $group['right'] : [];
                $max = .5;
                foreach ([$left, $right] as $side) {
                    foreach ($side as $r) { $max = max($max,abs((float)($r['delta'] ?? 0))); }
                }
                $groupName = (string)($group['name'] ?? '');
                $kind = (string)($group['kind'] ?? 'Nation');
                $phrase = match ($kind) {
                    'Kontinent' => 'dem Kontinent',
                    'Region' => 'der Region',
                    'Religion' => 'der Religionsgruppe',
                    default => 'der Nation'
                };
                $leftTitle = 'Wie wählt '.$groupName.'?';
                $rightTitle = 'Wer wählt '.$groupName.'?';
                $leftSubtitle = 'Wahlverhalten von Wählern aus '.$phrase.' '.$groupName;
                $rightSubtitle = 'Wahlverhalten hinsichtlich Bewerbern aus '.$phrase.' '.$groupName;
                // Zwei visuell getrennte Diagramme. Die Zeilen links und rechts sind
                // jeweils eigenständig sortiert und nicht miteinander gepaart.
                $w = 90.0; $gap = 6.0;
                $titleHeight = 23.0; $rowH = 6.9;
                $drawHeads = function() use ($w,$gap,$titleHeight,$leftTitle,$rightTitle,$leftSubtitle,$rightSubtitle) {
                    $y = $this->GetY();
                    $this->SetFillColor(25,120,46);
                    $this->Rect(12,$y,$w,$titleHeight,'F');
                    $this->SetFillColor(31,68,51);
                    $this->Rect(12+$w+$gap,$y,$w,$titleHeight,'F');
                    foreach ([[$leftTitle,$leftSubtitle,12],[$rightTitle,$rightSubtitle,12+$w+$gap]] as [$title,$subtitle,$x]) {
                        $this->SetTextColor(255,255,255);
                        $this->SetFont('Arial','B',9.5);
                        $this->SetXY($x+3,$y+2);
                        $this->Cell($w-6,5.5,$this->truncate($title,$w-6.5),0,0);
                        $this->SetFont('Arial','',7.4);
                        $lines = $this->wrap($subtitle,$w-7);
                        foreach (array_slice($lines,0,3) as $lineNumber=>$line) {
                            $this->SetXY($x+3,$y+8.4+$lineNumber*4.0);
                            $this->Cell($w-6,4.0,$line,0,0);
                        }
                    }
                    $this->SetY($y+$titleHeight+1);
                };
                $this->room($titleHeight+2*$rowH);
                $drawHeads();
                $n = max(count($left),count($right));
                if (!$n) { $this->para('Für diese Gruppe sind keine auswertbaren Vergleiche vorhanden.'); return; }
                for ($i=0;$i<$n;$i++) {
                    if ($this->GetY()+$rowH > $this->bottom) {
                        $this->AddPage(); $drawHeads();
                    }
                    $y = $this->GetY();
                    foreach ([$left, $right] as $j => $list) {
                        $x = 12+$j*($w+$gap);
                        if ($i % 2 === 0) {
                            $this->SetFillColor($j===0?240:243,$j===0?248:247,$j===0?241:245);
                            $this->Rect($x,$y,$w,$rowH,'F');
                        }
                        if (!isset($list[$i])) { continue; }
                        $r = $list[$i]; $delta = (float)($r['delta'] ?? 0);
                        $this->flag($r['code'] ?? '',$x+1.1,$y+1.6,4.7,3.5);
                        $this->SetFont('Arial','',7.45);
                        $this->SetTextColor(35,51,39);
                        $this->SetXY($x+6.7,$y+.8);
                        $this->Cell(43.8,5.3,$this->truncate($r['name']??'',43.1),0,0);
                        $zero=$x+62; $extent=10.5;
                        $this->SetDrawColor(148,166,152);
                        $this->Line($zero,$y+.8,$zero,$y+$rowH-.8);
                        $bar = min($extent,abs($delta)/$max*$extent);
                        if ($delta>0) {$this->SetFillColor(24,166,60);$this->Rect($zero,$y+2.15,$bar,2.7,'F');}
                        elseif ($delta<0) {$this->SetFillColor(228,107,127);$this->Rect($zero-$bar,$y+2.15,$bar,2.7,'F');}
                        $this->SetFont('Arial','B',7.2);
                        if ($delta>0) {$this->SetTextColor(20,138,42);}
                        elseif ($delta<0) {$this->SetTextColor(185,52,71);}
                        else {$this->SetTextColor(84,100,87);}
                        $this->SetXY($x+74,$y+.9);
                        $this->Cell(15.1,5.2,$this->txt(($delta>0?'+':'').number_format($delta,2,',','.')),0,0,'R');
                    }
                    $this->SetDrawColor(226,234,227);
                    $this->Line(12,$y+$rowH,102,$y+$rowH);
                    $this->Line(108,$y+$rowH,198,$y+$rowH);
                    $this->SetY($y+$rowH);
                }
                $this->Ln(3);
            }

            private function tocEntries(array $s) {
                $entries = [
                    ['1','Datengrundlage und Methodik',0],
                    ['1.1','Datenbasis und Kennzahlen',1],
                    ['1.2','Berechnung und Grenzen',1],
                    ['2','Wähler und Bewerber nach Nationalität',0],
                    ['2.1','Wählernationalitäten (vollständig)',1],
                    ['2.2','Bewerbernationalitäten (vollständig)',1],
                    ['3','Gesamtansicht: alle Wähler',0],
                    ['3.1','Beliebtheit aller Bewerbernationen',1],
                    ['3.2','Vollständige Kennzahlentabelle',1],
                    ['4','Vergleich der Top-Nationalitäten',0],
                    ['4.1','Die zwei Top-10-Listen',1],
                ];
                foreach (($s['nations']['pairs']??[]) as $i=>$p) {
                    $entries[]=['4.'.($i+2),(string)($p['name']??''),1];
                }
                $entries[]=['5','Kontinente und geografische Regionen',0];
                foreach (($s['geography']??[]) as $i=>$g) {
                    $entries[]=['5.'.($i+1),($g['kind']??'Region').': '.($g['name']??''),1];
                }
                $entries[]=['6','Religionsgruppen im Vergleich',0];
                foreach (($s['religions']??[]) as $i=>$g) {
                    $entries[]=['6.'.($i+1),($g['name']??''),1];
                }
                $entries = array_merge($entries,[
                    ['7','Patriotismus-Index',0],
                    ['7.1','Abweichungen nach Wählernationalität',1],
                    ['7.2','Vollständige Kennzahlentabelle',1],
                    ['8','Rassismus-Index: statistische Spannweite',0],
                    ['8.1','Spannweite nach Wählernationalität',1],
                    ['8.2','Vollständige Kennzahlentabelle',1]
                ]);
                return $entries;
            }

            private function cover(array $quality) {
                $this->cover = true;
                $this->AddPage();
                $this->SetFillColor(240,248,241);
                $this->Rect(0,0,210,297,'F');
                $this->SetFillColor(17,165,13);
                $this->Rect(0,0,210,6,'F');
                $this->SetXY(18,43);
                $this->SetTextColor(25,115,36);
                $this->SetFont('Arial','B',12);
                $this->Cell(170,10,'WEH e.V.  /  STATISTISCHE AUSWERTUNG',0,1);
                $this->SetXY(18,68);
                $this->SetFont('Arial','B',25);
                $this->SetTextColor(27,51,31);
                $this->MultiCell(175,14,$this->txt('Wahlverhalten bei BA-Abstimmungen'),0,'L');
                $this->SetXY(18,125);
                $this->SetFont('Arial','',12);
                $this->SetTextColor(70,86,74);
                $this->MultiCell(175,8,$this->txt('Nationalitäten, geografische Regionen und definierte Religionsgruppen. Vergleich der Wahlwahrscheinlichkeit in Prozentpunkten.'),0,'L');
                $this->SetXY(18,182);
                $this->SetFont('Arial','',10);
                $this->Cell(160,8,$this->txt('Auswertbare Abstimmungen: '.$this->number($quality['polls_included']??0)),0,1);
                $this->SetX(18);
                $this->Cell(160,8,$this->txt('Gültige Wahlteilnahmen: '.$this->number($quality['ballots']??0)),0,1);
                $this->SetX(18);
                $this->Cell(160,8,$this->txt('Bewerberpositionen: '.$this->number($quality['candidates_included']??0)),0,1);
                $this->SetXY(18,270);
                $this->SetFont('Arial','',9);
                $this->Cell(174,9,$this->txt('Berichtsstand: '.$this->created.'  |  Mindestwerte: 2 Wähler / 1 Bewerber'),0,0);
                $this->cover = false;
            }

            private function contents(array $entries) {
                $this->toc = true;
                // FPDF erstellt Sprungziele unabhaengig davon, auf welcher Seite
                // die Kapitel spaeter landen. start() verankert jedes Ziel.
                $this->tocLinks = [];
                foreach ($entries as [$id, $title, $level]) {
                    $this->tocLinks[$id] = $this->AddLink();
                }
                $this->currentSection = 'Inhaltsverzeichnis';
                $this->currentChapter = 'Inhaltsverzeichnis';
                $this->AddPage();
                $this->SetY(32);
                $this->SetFont('Arial','B',17);
                $this->SetTextColor(25,109,38);
                $this->Cell(186,12,'Inhaltsverzeichnis',0,1);
                $this->Ln(3);
                foreach ($entries as [$id,$title,$level]) {
                    if ($this->GetY()+5.4 > 278) {
                        $this->AddPage();
                    }
                    $y=$this->GetY();
                    if ($level===0) {
                        $this->SetFillColor(237,248,238);
                        $this->Rect(12,$y,186,6.3,'F');
                        $this->SetFont('Arial','B',8.6);
                        $this->SetTextColor(24,99,36);
                    } else {
                        $this->SetFont('Arial','',8.25);
                        $this->SetTextColor(54,69,57);
                    }
                    $indent=$level?7:1;
                    $this->SetXY(12+$indent,$y+.55);
                    $this->Cell(150-$indent,4.9,$this->truncate($id.'  '.$title,148-$indent),0,0);
                    $this->SetXY(170,$y+.55);
                    $this->Cell(27,4.9,(string)($this->tocPages[$id]??'-'),0,0,'R');
                    // Ganze TOC-Zeile (einschl. Seitenzahl) anklickbar machen.
                    $this->Link(12,$y,186,$level?5.2:6.7,$this->tocLinks[$id]);
                    $this->SetY($y+($level?5.2:6.7));
                }
                $this->toc=false;
            }

            private function nationalityCounts($id, $title, array $rows, $subject) {
                $this->start($id,$title,2);
                $this->para($subject.' nach Nationalität; vollständige Rangliste aller in auswertbaren Daten tatsächlich vorkommenden Länder.',9);
                $table=[];
                foreach ($rows as $i=>$r) {
                    $table[]=[(string)($i+1),['value'=>$r['name']??'','code'=>$r['code']??''],$this->number($r['count']??0)];
                }
                $this->table(['Rang','Nationalität',$subject],$table,[15,129,42],1);
            }

            /**
             * Zeigt nur Nationalitaeten, die in die tatsaechlichen PDF-Vergleiche
             * eingegangen sind: links Waehler, rechts Bewerber. Die Listen
             * stammen aus denselben gefilterten Zeilen wie die Diagramme
             * (Mindestwerte 2 Waehler / 1 Bewerber), nicht aus country.json.
             */
            private function groupCountryChips(array $group) {
                $lists = [
                    ['Berücksichtigte Wählernationalitäten', $group['voterCountries'] ?? []],
                    ['Berücksichtigte Bewerbernationalitäten', $group['applicantCountries'] ?? []],
                ];
                foreach ($lists as [$heading, $members]) {
                    if (!is_array($members) || !$members) { continue; }
                    $this->room(15);
                    $this->SetX(12);
                    $this->SetFont('Arial','B',8.5);
                    $this->SetTextColor(34,111,47);
                    $this->Cell(186,6.0,$this->txt($heading.' ('.count($members).')'),0,1);
                    $lineH=6.25;
                    $leftX=12.0;
                    $x=$leftX;
                    $y=$this->GetY()+1;
                    foreach ($members as $member) {
                        if (!is_array($member)) { continue; }
                        $name=(string)($member['name'] ?? '');
                        $countryCode=(string)($member['code'] ?? '');
                        if ($name==='' || !preg_match('/^[a-z]{2}$/',$countryCode)) { continue; }
                        $this->SetFont('Arial','',7.45);
                        $itemW=min(186.0,max(20.0,$this->GetStringWidth($this->txt($name))+12.0));
                        if ($x>$leftX && $x+$itemW>198.0) {
                            $x=$leftX; $y+=$lineH;
                        }
                        if ($y+$lineH>$this->bottom) {
                            $this->AddPage();
                            $y=$this->GetY()+1;
                            $x=$leftX;
                        }
                        $this->SetFillColor(241,247,242);
                        $this->Rect($x,$y,$itemW,$lineH-0.55,'F');
                        $this->flag($countryCode,$x+1.5,$y+1.2,5.1,3.6);
                        $this->SetTextColor(39,59,44);
                        $this->SetFont('Arial','',7.45);
                        $this->SetXY($x+8.4,$y+0.55);
                        $this->Cell($itemW-10,4.7,$this->truncate($name,$itemW-10.2),0,0);
                        $x+=$itemW+2;
                    }
                    $this->SetY($y+$lineH+3);
                }
            }

            public function renderReport(array $data) {
                $s = is_array($data['sections']??null)?$data['sections']:[];
                $q = is_array($data['quality']??null)?$data['quality']:[];
                $this->cover($q);
                $this->contents($this->tocEntries($s));

                $this->start('1','Datengrundlage und Methodik');
                $this->start('1.1','Datenbasis und Kennzahlen',2);
                $this->note('Auswertbare Abstimmungen',$this->number($q['polls_included']??0));
                $this->note('Bewerberpositionen aus gültigen PDFs',$this->number($q['candidates_included']??0));
                $this->note('Gültige Wahlteilnahmen',$this->number($q['ballots']??0));
                $this->note('Wahlteilnahmen mit bekannter Nationalität',$this->number($q['ballots_known']??0));
                $this->start('1.2','Berechnung und Aussagegrenzen',2);
                $this->para('Wahlwahrscheinlichkeit: Anteil gültiger Wahlteilnahmen, bei denen die jeweilige Bewerberposition gewählt wurde. Die Abweichung gegenüber der Referenz wird in Prozentpunkten (pp) angegeben.',9);
                $this->para('Gesamtansicht: Erwartungswert bei gleichmäßiger Stimmverteilung auf Bewerber derselben Abstimmung. Andere Wählergruppen: Vergleich mit den übrigen Wählern der jeweils auswertbaren Abstimmungen. Für Regionen und Religionsgruppen werden die nationalen Gruppenwerte gleichgewichtet gemittelt.',9);
                $this->para('Mindestgrenze für die statistischen Vergleiche: 2 unterschiedliche Wähler und 1 Bewerber pro nationaler Teilgruppe. Die Zähltabellen bleiben vollständig und enthalten auch Nationen mit nur einem Wähler. Kleine Stichproben können extreme Abweichungen erzeugen; ein Unterschied ist kein Kausalnachweis.',9);
                $this->para('Bewerberzahlen zählen Bewerbungen, nicht eindeutige Personen. Religionen sind definierte, teilweise überlappende Ländergruppen und beschreiben nicht die Religionszugehörigkeit einzelner Personen. Patriotismus- und Rassismus-Indizes sind rein deskriptive Größen und dürfen nicht als Motive einzelner Wähler verstanden werden.',9);

                $this->start('2','Wähler und Bewerber nach Nationalität');
                $this->nationalityCounts('2.1','Wählernationalitäten (vollständig)',$s['counts']['voters']??[],'Wähler');
                $this->nationalityCounts('2.2','Bewerbernationalitäten (vollständig)',$s['counts']['applicants']??[],'Bewerbungen');

                $this->start('3','Gesamtansicht: alle Wähler');
                $this->start('3.1','Beliebtheit aller Bewerbernationen',2);
                $this->para('Abweichung der tatsächlichen Wahlwahrscheinlichkeit pro Bewerber von der jeweiligen Gleichverteilungsreferenz innerhalb der Abstimmungen. 0 pp = Erwartungswert. Positive Werte = überdurchschnittlich gewählt.',9);
                $this->chart($s['all']??[]);
                $this->start('3.2','Vollständige Kennzahlentabelle',2);
                $table=[];
                foreach (($s['all']??[]) as $i=>$r) {
                    $d=(float)($r['delta']??0);
                    $table[]=[(string)($i+1),['value'=>$r['name']??'','code'=>$r['code']??''],
                        $this->pct($r['rate']??0),$this->pct($r['reference']??0),
                        ['value'=>$this->pp($d),'delta'=>$d]];
                }
                $this->table(['#','Bewerbernation','Gewählt','Erwartet','Differenz'],$table,[12,82,29,29,34],1,7.8);

                $this->start('4','Vergleich der Top-Nationalitäten');
                $this->start('4.1','Zehn stärkste Wähler- und Bewerbernationen',2);
                $this->para('Zwei separate Top-10-Listen: links nach Zahl der Wähler, rechts nach Zahl der Bewerbungen. Die Vereinigungsmenge bestimmt die nachfolgenden Nationenvergleiche.',9);
                $this->dividerTitle('Top 10 Wählernationalitäten');
                $rows=[];
                foreach (($s['nations']['topVoters']??[]) as $i=>$r) {
                    $rows[]=[(string)($i+1),['value'=>$r['name']??'','code'=>$r['code']??''],$this->number($r['count']??0)];
                }
                $this->table(['#','Wählernation','Wähler'],$rows,[14,127,45],1);
                $this->dividerTitle('Top 10 Bewerbernationalitäten');
                $rows=[];
                foreach (($s['nations']['topApplicants']??[]) as $i=>$r) {
                    $rows[]=[(string)($i+1),['value'=>$r['name']??'','code'=>$r['code']??''],$this->number($r['count']??0)];
                }
                $this->table(['#','Bewerbernation','Bewerbungen'],$rows,[14,127,45],1);
                foreach (($s['nations']['pairs']??[]) as $i=>$g) {
                    $this->start('4.'.($i+2),'Nationalität: '.($g['name']??''),2);
                    $this->paired($g);
                }

                $this->start('5','Kontinente und geografische Regionen');
                $this->para('Kontinente und definierte Regionen. Regionsgruppen werden über die beteiligten Wähler- und Bewerbernationalitäten gleichgewichtet gemittelt.',9);
                foreach (($s['geography']??[]) as $i=>$g) {
                    $this->start('5.'.($i+1),($g['kind']??'Region').': '.($g['name']??''),2);
                    $this->groupCountryChips($g);
                    $this->paired($g);
                }

                $this->start('6','Religionsgruppen im Vergleich');
                $this->para('Alle Religionsgruppen in beiden Auswertungsrichtungen. Die Gruppen bestehen aus definierten Länderlisten; eine individuelle Religionszugehörigkeit wird nicht erhoben.',9);
                foreach (($s['religions']??[]) as $i=>$g) {
                    $this->start('6.'.($i+1),'Religionsgruppe: '.($g['name']??''),2);
                    $this->groupCountryChips($g);
                    $this->paired($g);
                }

                $this->start('7','Patriotismus-Index');
                $this->start('7.1','Abweichungen nach Wählernationalität',2);
                $this->para('Vergleich der Wahlwahrscheinlichkeit von Bewerbern der eigenen Nationalität mit derjenigen bei den übrigen Wählern. Deskriptiv, keine Aussage über Motive.',9);
                $this->chart($s['patriotism']??[]);
                $this->start('7.2','Vollständige Kennzahlentabelle',2);
                $rows=[];
                foreach (($s['patriotism']??[]) as $i=>$r) {
                    $d=(float)($r['delta']??0);
                    $rows[]=[(string)($i+1),['value'=>$r['name']??'','code'=>$r['code']??''],
                        $this->pct($r['rate']??0),$this->pct($r['reference']??0),
                        ['value'=>$this->pp($d),'delta'=>$d]];
                }
                $this->table(['#','Wählernation','Eigene Quote','Referenz','Differenz'],$rows,[12,80,36,28,30],1,7.8);

                $this->start('8','Rassismus-Index: statistische Spannweite');
                $this->start('8.1','Spannweite nach Wählernationalität',2);
                $this->para('Differenz zwischen der höchsten und niedrigsten Abweichung der Bewerbernationalitäten je Wählergruppe. Diese Spannweite misst ausdrücklich nicht Rassismus; besonders kleine Stichproben können stark schwanken.',9);
                $spreadRows=[];
                foreach (($s['racism']??[]) as $r) {
                    $spreadRows[]=['name'=>$r['name']??'','code'=>$r['code']??'', 'delta'=>(float)($r['spread']??0)];
                }
                $this->chart($spreadRows);
                $this->start('8.2','Vollständige Kennzahlentabelle',2);
                $rows=[];
                foreach (($s['racism']??[]) as $i=>$r) {
                    $best=$r['best']??[]; $worst=$r['worst']??[];
                    $rows[]=[(string)($i+1),['value'=>$r['name']??'','code'=>$r['code']??''],
                        (string)($best['name']??''),
                        ['value'=>$this->pp($best['delta']??0),'delta'=>(float)($best['delta']??0)],
                        (string)($worst['name']??''),
                        ['value'=>$this->pp($worst['delta']??0),'delta'=>(float)($worst['delta']??0)],
                        $this->pp($r['spread']??0)];
                }
                $this->table(['#','Wählernation','Max: Bewerber','Max','Min: Bewerber','Min','Spanne'],
                    $rows,[9,43,34,23,34,23,20],1,7.1);
            }
        };

        // Zweiter Durchlauf schreibt die tatsächlichen Seitenzahlen ins Inhaltsverzeichnis.
        // Eine TOC-Zeile belegt unabhängig von der Seitenzahl dieselbe Höhe.
        $pdf->renderReport($report);
        $pageNumbers = $pdf->getTocNumbers();
        // Klassenname vor dem Freigeben merken: get_class(null) wirft seit PHP 8
        // einen TypeError. Derselbe FPDF-Untertyp wird fuer den zweiten
        // Durchlauf mit den ermittelten Inhaltsverzeichnis-Seiten neu erzeugt.
        $klass = get_class($pdf);
        unset($pdf);
        $pdf = new $klass($flagFiles);
        $pdf->setTocNumbers($pageNumbers);
        $pdf->renderReport($report);
        $content = $pdf->Output('S');
        if (!is_string($content) || strlen($content) < 100 || substr($content, 0, 5) !== '%PDF-') {
            throw new RuntimeException('FPDF hat kein gültiges PDF erzeugt.');
        }
        return $content;
    } finally {
        foreach ($flagFiles as $file) { @unlink($file); }
    }
}

// -----------------------------------------------------------------------------
// Request-Handling BEVOR template.php (das bereits HTML/CSS ausgibt).
// Kein AJAX: regulärer CSRF-geschuetzter POST und anschliessender Redirect.
// -----------------------------------------------------------------------------
if (defined('WV_LIBRARY_ONLY') && WV_LIBRARY_ONLY) { return; }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['Webmaster']) || empty($_SESSION['valid'])) {
    http_response_code(403);
    exit('Zugriff verweigert: Webmaster-Berechtigung erforderlich.');
}
$wvRoot = dirname(__DIR__);
if (empty($_SESSION['wv_token'])) { $_SESSION['wv_token'] = bin2hex(random_bytes(32)); }
$wvToken = $_SESSION['wv_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wvAction = (string)($_POST['wv_action'] ?? '');
    if (!in_array($wvAction, ['refresh', 'pdf'], true) ||
        !isset($_POST['wv_token']) || !hash_equals($wvToken, (string)$_POST['wv_token'])) {
        http_response_code(400);
        exit('Ungueltige Aktion oder CSRF-Token.');
    }
    if ($wvAction === 'pdf') {
        // template.php initialisiert $conn, gibt aber bereits Layout aus.
        // Dieses wird fuer den binaeren PDF-Download vollstaendig verworfen.
        $wvBufferLevel = ob_get_level();
        ob_start();
        try {
            require __DIR__.'/template.php';
            $wvAuthorized = isset($conn) && $conn instanceof mysqli
                && auth($conn) && !empty($_SESSION['Webmaster']);
        } finally {
            while (ob_get_level() > $wvBufferLevel) { ob_end_clean(); }
        }
        if (!$wvAuthorized) { http_response_code(403); exit('Zugriff verweigert.'); }
        $raw = $_POST['wv_report'] ?? null;
        if (!is_string($raw) || strlen($raw) > 4000000 || strlen($raw) < 30) {
            http_response_code(413); exit('Berichtsdaten fehlen oder sind zu gross.');
        }
        $report = json_decode($raw, true);
        if (!is_array($report) || json_last_error() !== JSON_ERROR_NONE ||
            ($report['schema'] ?? null) !== 1 || !isset($report['sections']) ||
            !is_array($report['sections'])) {
            http_response_code(400); exit('Ungueltiges PDF-Berichtsformat.');
        }
        // FPDF ist bereits lokal in /WEH/PHP/FPDF/ vorhanden.
        // Keine externen Prozesse, keine temporaeren Dateien, kein Python.
        try {
            @set_time_limit(300);
            $content = wv_create_report_pdf($report);
            session_write_close();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="WEH_Wahlverhalten_'.date('Y-m-d').'.pdf"');
            header('Content-Length: '.strlen($content));
            header('Cache-Control: private, no-store');
            echo $content;
        } catch (Throwable $ex) {
            error_log('Wahlverhalten PDF: '.$ex->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo 'PDF-Export: '.$ex->getMessage();
        }
        exit;
    }
    // Session sperrt sonst parallele Requests des gleichen Admins waehrend der JSON-Aktualisierung.
    session_write_close();
    $wvFlash = wv_refresh_json($wvRoot);
    session_start();
    $_SESSION['wv_flash'] = $wvFlash;
    session_write_close();
    header('Location: Wahlverhalten.php', true, 303);
    exit;
}

$wvFlash = $_SESSION['wv_flash'] ?? null;
unset($_SESSION['wv_flash']);

// Vor dem Laden des Templates keine HTML-Ausgaben erzeugen.
require __DIR__.'/template.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div style="padding:1rem;color:#ff9494">WEH-Datenbankverbindung fehlt in template.php.</div>';
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');
if (!auth($conn) || empty($_SESSION['Webmaster'])) {
    http_response_code(403);
    echo '<p>Zugriff verweigert.</p>';
    exit;
}
// Das Vereinsmenue erhaelt weiterhin seine bestehenden WEH.css-Regeln.
echo '<link rel="stylesheet" href="WEH.css" media="screen">';
load_menu();
$wvData = null;
$wvError = null;
try {
    $wvData = wv_compute($conn, $wvRoot);
} catch (Throwable $ex) {
    error_log('Wahlverhalten.php: '.$ex->getMessage());
    $wvError = $ex->getMessage();
}
$wvJson = json_encode($wvData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($wvJson === false) { $wvError = 'Auswertungsdaten konnten nicht kodiert werden.'; }
?>
<style>
/* Wahlverhalten: Stile sind bewusst auf diese Seite begrenzt. WEH.css fuer das Menue separat laden. */
.wv-page{--wv-accent:#11a50d;--wv-surface:#171d19;--wv-border:#2e3c32;--wv-text:#edf4ee;--wv-muted:#9ead9f;--wv-red:#f2848f;--wv-green:#5de46c;color:var(--wv-text);font:400 14px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;background:#0e1510;width:min(1370px,calc(100% - 36px));padding:24px;margin:30px auto 60px;border:1px solid #213327;border-radius:18px;box-sizing:border-box}
.wv-page *{box-sizing:border-box}
.wv-page h1,.wv-page h2,.wv-page p{margin:0;color:inherit}
.wv-page h1{font-size:clamp(24px,2.15vw,34px);font-weight:750;letter-spacing:-.035em}
.wv-page button,.wv-page input,.wv-page select{font:inherit}
.wv-page button{cursor:pointer}
.wv-page button:focus-visible,.wv-page select:focus-visible,.wv-page input:focus-visible{outline:2px solid #57e363;outline-offset:2px}
.wv-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:16px}
.wv-actions{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap}
.wv-action-buttons{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap}
.wv-refresh-stack{display:flex;flex-direction:column;align-items:flex-start;gap:5px}
.wv-actions .wv-date{display:block;margin-left:2px}
.wv-export{background:#16351c;border-color:#356f40;color:#e8f4e9}
.wv-export:hover{background:#20502a}
.wv-date{color:var(--wv-muted);font-size:12px}
.wv-refresh{background:var(--wv-accent);color:white;border:1px solid #34ca31;border-radius:10px;padding:10px 15px;display:flex;align-items:center;gap:9px;font-weight:700}
.wv-refresh:hover{background:#128d10}
.wv-refresh:disabled{opacity:.6}
.wv-refresh svg{width:16px;height:16px}
.wv-alert{border:1px solid;border-radius:10px;padding:11px 14px;margin-bottom:12px;font-size:13px}
.wv-alert--success{background:#102e19;color:#bff8c6;border-color:#227133}
.wv-alert--error{background:#351c20;color:#ffc8ca;border-color:#85424b}
.wv-panel{background:var(--wv-surface);border:1px solid var(--wv-border);border-radius:14px}
.wv-controls{padding:18px;margin-bottom:14px}
.wv-switch{display:inline-flex;gap:3px;background:#0b130e;border:1px solid #344639;border-radius:10px;padding:4px;margin:0;flex-shrink:0}
.wv-switch button{border:0;border-radius:7px;padding:8px 13px;background:transparent;color:#aabdae;font-size:13px;font-weight:680}
.wv-switch button.wv-active{background:#154c1b;color:white}
.wv-field-grid{display:grid;grid-template-columns:minmax(185px,1fr) minmax(185px,1fr) minmax(260px,1.35fr);gap:13px;align-items:end}
.wv-field{min-width:0;position:relative}
.wv-label{display:block;color:#a9bdad;font-size:11px;letter-spacing:.065em;text-transform:uppercase;font-weight:730;margin-bottom:7px}
.wv-select,.wv-group-button{background:#0c1510;color:#eaf2eb;border:1px solid #384b3d;border-radius:9px;width:100%;min-height:44px;padding:9px 12px}
.wv-select:hover,.wv-group-button:hover{border-color:#599264}
.wv-group-button{display:flex;align-items:center;gap:9px;text-align:left}
.wv-group-button>.wv-group-name{flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-weight:650}
.wv-arrow{flex:0 0 auto;width:7px;height:7px;border-bottom:2px solid #abc0ad;border-right:2px solid #abc0ad;transform:rotate(45deg);margin-left:auto;margin-top:-3px}
.wv-flag{width:27px;height:19px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 27px;overflow:hidden;border-radius:3px;background:#25392a;border:1px solid #ffffff24}
.wv-flag img{width:100%;height:100%;object-fit:cover}
.wv-placeholder-symbol{font-size:15px;color:#cad7c9}
.wv-menu{display:none;position:absolute;z-index:150;top:calc(100% + 6px);left:0;right:0;background:#101c13;border:1px solid #436349;border-radius:11px;padding:9px;box-shadow:0 15px 45px #000a}
.wv-menu.wv-open{display:block}
.wv-menu-search{width:100%;padding:9px 11px;border-radius:7px;background:#0b120c;border:1px solid #39513d;color:white;outline:none;margin-bottom:7px}
.wv-menu-list{max-height:320px;overflow-y:auto}
.wv-menu-option{background:transparent;border:0;width:100%;display:flex;align-items:center;gap:10px;padding:8px;border-radius:7px;text-align:left;color:#e5f1e7}
.wv-menu-option:hover,.wv-menu-option[aria-selected="true"]{background:#214329}
.wv-option-meta{margin-left:auto;font-size:11px;color:#91a697}
.wv-control-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.wv-switch--metric{margin:0}
.wv-min-users{margin-left:auto;display:flex;align-items:center;gap:8px}
.wv-min-users select{background:#0c1510;color:#eaf2eb;border:1px solid #384b3d;border-radius:9px;height:41px;padding:8px 12px;cursor:pointer;font-size:12px;font-weight:670}
.wv-min-users select:hover{border-color:#599264}
.wv-min-users select{max-width:190px}
.wv-chart{padding:18px;width:100%}
.wv-group-context{display:flex;align-items:center;justify-content:flex-start;flex-wrap:wrap;gap:8px 14px;padding:13px 15px;margin-bottom:16px;border:1px solid #344c39;border-radius:11px;background:linear-gradient(110deg,#142b1a 0%,#111c15 68%,#142019 100%);min-height:65px}
.wv-group-context[hidden]{display:none}
.wv-context-title{flex:0 1 auto;min-width:0;font-size:clamp(13px,1.18vw,16px);line-height:1.45;font-weight:600;letter-spacing:-.01em;color:#d8e6da}
.wv-context-title strong{font-weight:750;color:#72e67e}
.wv-context-flags{display:flex;flex:0 1 auto;flex-direction:column;width:max-content;max-width:min(425px,100%);min-width:0;max-height:175px;align-items:flex-start;justify-content:center;gap:5px;overflow:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#395f40 transparent;padding:4px 5px}
.wv-context-flag-line{display:flex;flex:0 0 auto;align-items:center;justify-content:flex-start;gap:6px;white-space:nowrap}
.wv-context-flags .wv-flag{width:34px;height:25px;flex:0 0 34px;border:1px solid #ffffff35;box-shadow:0 1px 5px #0005;border-radius:4px}
/* Gesamtansicht: keine Hoehen- bzw. Zeilenbegrenzung; alle Flaggen anzeigen. */
.wv-context-flags.wv-context-flags--all{max-height:none;max-width:none;overflow:visible}
@media(max-width:850px){.wv-group-context{align-items:center;gap:8px 12px}.wv-context-title{flex:0 1 auto;width:auto}.wv-context-flags{flex:0 1 auto;width:max-content;max-width:100%;max-height:175px;justify-content:flex-start}}
@media(max-width:660px){.wv-group-context{padding:12px;margin-bottom:12px}.wv-context-flags .wv-flag{width:29px;height:21px;flex-basis:29px}}
.wv-chart-header,.wv-chart-row{display:grid;grid-template-columns:minmax(175px,1fr) minmax(185px,1.6fr) 105px;gap:13px;align-items:center}
.wv-chart-header{padding:0 10px 8px;border-bottom:1px solid #344536;color:#829b86;text-transform:uppercase;font-size:10px;letter-spacing:.07em;font-weight:740}
.wv-chart-row{padding:10px;border-bottom:1px solid #29382e;min-height:47px;transition:background .13s}
.wv-chart-row:hover{background:#1c3020}
.wv-chart-row:last-child{border-bottom:0}
.wv-target-cell{display:flex;gap:10px;align-items:center;min-width:0;font-size:13px;font-weight:640}
.wv-target-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Nur Regionszeilen: Titel zuerst, dann die wirklich beruecksichtigten Laenderflaggen. */
.wv-target-cell--region{gap:9px;justify-content:space-between;align-items:center;flex-wrap:wrap}
.wv-target-cell--region .wv-target-name{flex:0 1 auto;white-space:normal;overflow:visible}
.wv-region-row-flags{display:flex;flex:0 1 auto;flex-direction:column;align-items:flex-end;justify-content:center;gap:4px;max-height:116px;overflow-y:auto;min-width:0;scrollbar-width:thin}
.wv-region-flag-line{display:flex;align-items:center;justify-content:flex-end;gap:4px;flex-wrap:nowrap;flex:0 0 auto}
.wv-region-row-flags .wv-flag{width:23px;height:17px;flex:0 0 23px;border-radius:3px}
.wv-diverging{height:20px;width:100%;position:relative}
.wv-diverging::after{content:'';position:absolute;left:50%;top:0;bottom:0;background:#728674;width:1px}
.wv-diverging-fill{position:absolute;top:4px;height:12px;min-width:2px;border-radius:3px}
.wv-diverging-fill.wv-positive{left:50%;background:linear-gradient(90deg,#17851d,#45e259)}
.wv-diverging-fill.wv-negative{right:50%;background:linear-gradient(90deg,#f18a92,#b43e50)}
.wv-delta{text-align:right;font-size:13px;font-weight:780;white-space:nowrap;font-variant-numeric:tabular-nums}
.wv-delta.wv-positive{color:#5ae96c}.wv-delta.wv-negative{color:#ff8b94}.wv-delta.wv-neutral{color:#afc0b3}
.wv-empty{padding:24px;text-align:center;color:#a7b7aa;font-size:13px}
@media(max-width:850px){.wv-field-grid{grid-template-columns:1fr 1fr}.wv-field:last-child{grid-column:1 / -1}.wv-chart-header,.wv-chart-row{grid-template-columns:minmax(150px,1fr) minmax(110px,1.3fr) 96px;gap:9px}.wv-control-top{gap:8px}.wv-switch button{padding:8px 10px}}
@media(max-width:660px){.wv-page{width:calc(100% - 12px);margin:10px auto;padding:13px}.wv-controls,.wv-chart{padding:13px}.wv-field-grid{grid-template-columns:1fr}.wv-field:last-child{grid-column:auto}.wv-control-top{align-items:stretch;flex-direction:column}.wv-switch{display:flex;width:100%}.wv-switch button{flex:1;min-width:0;font-size:12px;padding:8px 6px}.wv-min-users{margin-left:0;justify-content:flex-end;flex-wrap:wrap}.wv-min-users select{flex:1;min-width:130px;max-width:none}.wv-chart-header{display:none}.wv-chart-row{grid-template-columns:minmax(115px,1fr) minmax(75px,1.1fr) 88px;gap:6px;padding:10px 3px}.wv-hero{align-items:flex-start}.wv-action-buttons{width:100%}.wv-refresh{justify-content:center}}
</style>
<section class="wv-page" aria-label="Auswertung des BA-Wahlverhaltens">
  <header class="wv-hero">
    <div>
      <h1>Wahlverhalten nach Herkunft</h1>
    </div>
    <div class="wv-actions">
      <div class="wv-action-buttons">
        <div class="wv-refresh-stack">
          <form method="post" action="Wahlverhalten.php" id="wv-refresh-form">
            <input type="hidden" name="wv_action" value="refresh">
            <input type="hidden" name="wv_token" value="<?=wv_html($wvToken)?>">
            <button type="submit" class="wv-refresh" id="wv-refresh-button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.6 9a7 7 0 0 1 12.4-2l2 5M4 12l2 5a7 7 0 0 0 12.5-2"/></svg>
              <span>Bewerber-JSON aktualisieren</span>
            </button>
          </form>
          <?php if ($wvData !== null && $wvData['json_updated']): ?>
            <span class="wv-date">Letzte Aktualisierung: <?=wv_html(date('d.m.Y H:i', $wvData['json_updated']))?></span>
          <?php endif; ?>
        </div>
        <?php if ($wvData !== null && !$wvError): ?>
          <button type="button" class="wv-refresh wv-export" id="wv-export-button" title="Gesamtauswertung als PDF herunterladen">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M5 17v4h14v-4"/><path d="M6 3h5m6 0h1v9"/></svg>
            <span>PDF-Auswertung erstellen</span>
          </button>
        <?php endif; ?>
      </div>
    </div>
  </header>
  <?php if ($wvFlash): ?>
    <div class="wv-alert <?=!empty($wvFlash['ok'])?'wv-alert--success':'wv-alert--error'?>" role="status"><?=wv_html($wvFlash['message']??'')?></div>
  <?php endif; ?>
  <?php if ($wvError): ?>
    <div class="wv-alert wv-alert--error" role="alert"><strong>Auswertung fehlgeschlagen:</strong> <?=wv_html($wvError)?></div>
  <?php elseif ($wvData !== null): ?>
    <div class="wv-panel wv-controls">
      <div class="wv-control-top">
        <div class="wv-switch" role="group" aria-label="Blickrichtung">
          <button type="button" id="wv-forward" class="wv-active" aria-pressed="true">Wähler → Bewerber</button>
          <button type="button" id="wv-reverse" aria-pressed="false">Bewerber → Wähler</button>
        </div>
        <div class="wv-switch wv-switch--metric" role="group" aria-label="Auswertungskennzahl">
          <button type="button" id="wv-metric-hit" class="wv-active" aria-pressed="true">Wahlwahrscheinlichkeit</button>
          <button type="button" id="wv-metric-points" aria-pressed="false">3/2/1-Punkteanteil</button>
        </div>
        <div class="wv-min-users">
          <select id="wv-min-users" aria-label="Mindestanzahl unterschiedlicher Wähler" title="Mindestanzahl unterschiedlicher Wähler der betrachteten Herkunftsgruppe">
            <?php for ($n=1; $n<=10; $n++): ?>
              <option value="<?=$n?>" <?=$n===1?'selected':''?>>Mind. <?=$n?> Wähler</option>
            <?php endfor; ?>
          </select>
          <select id="wv-min-candidates" aria-label="Mindestanzahl Bewerber" title="Mindestanzahl Bewerbungen je Nationalität; bei Regionen und Religionen pro enthaltenem Land">
            <?php for ($n=1; $n<=10; $n++): ?>
              <option value="<?=$n?>" <?=$n===1?'selected':''?>>Mind. <?=$n?> Bewerber</option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="wv-field-grid">
        <div class="wv-field">
          <label class="wv-label" for="wv-source-type">Wähler gruppieren nach</label>
          <select id="wv-source-type" class="wv-select"><option value="land">Nationalität</option><option value="kontinent">Kontinent</option><option value="region">Region</option><option value="religion">Religion</option></select>
        </div>
        <div class="wv-field">
          <label class="wv-label" for="wv-target-type">Bewerber gruppieren nach</label>
          <select id="wv-target-type" class="wv-select"><option value="land">Nationalität</option><option value="kontinent">Kontinent</option><option value="region">Region</option><option value="religion">Religion</option></select>
        </div>
        <div class="wv-field">
          <label class="wv-label" for="wv-group-trigger" id="wv-group-label">Wählergruppe auswählen</label>
          <button id="wv-group-trigger" class="wv-group-button" type="button" aria-expanded="false" aria-haspopup="listbox"><span class="wv-flag">◈</span><span class="wv-group-name">Wählen ...</span><span class="wv-arrow"></span></button>
          <div id="wv-group-menu" class="wv-menu">
            <input type="search" id="wv-group-search" class="wv-menu-search" placeholder="Land, Kontinent, Region oder Religion suchen ..." autocomplete="off" aria-label="Gruppe suchen">
            <div id="wv-group-options" class="wv-menu-list" role="listbox" aria-label="Verfügbare Gruppen"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="wv-panel wv-chart">
      <div id="wv-group-context" class="wv-group-context" hidden aria-live="polite">
        <div class="wv-context-title" id="wv-context-title"></div>
        <div class="wv-context-flags" id="wv-context-flags" aria-label="In den Daten vorkommende Länder"></div>
      </div>
      <div class="wv-chart-header"><span id="wv-chart-first-heading">Bewerber</span><span id="wv-chart-rate-heading" style="text-align:center">Abweichung</span><span id="wv-chart-value-heading" style="text-align:right">Differenz (pp)</span></div>
      <div id="wv-chart-list" role="list" class="wv-chart-list" aria-live="polite"></div>
    </div>
  <?php endif; ?>
</section>
<?php if (!$wvError && $wvJson!==false && $wvData!==null): ?>
<script type="application/json" id="wv-data"><?=$wvJson?></script>
<script>
(function(){
  'use strict';
  const data=JSON.parse(document.getElementById('wv-data').textContent);
  const $=id=>document.getElementById(id);
  const sourceSelect=$('wv-source-type'),targetSelect=$('wv-target-type');
  const trigger=$('wv-group-trigger'),menu=$('wv-group-menu'),menuSearch=$('wv-group-search');
  const groupOptions=$('wv-group-options'),minUsersSelect=$('wv-min-users'),minCandidatesSelect=$('wv-min-candidates');
  const forward=$('wv-forward'),reverse=$('wv-reverse'),metricHit=$('wv-metric-hit'),metricPoints=$('wv-metric-points');
  const state={direction:'forward',source:'land',target:'land',group:null,metric:'hit',minUsers:1,minCandidates:1};
  const matrices=data.matrices;
  const allMatrices=data.all_matrices||{};
  const candidateCounts=data.candidate_counts||{land:{},kontinent:{},region:{},religion:{}};
  const sourceCountryFlags=data.source_country_flags||{land:{},kontinent:{},region:{},religion:{}};
  const candidateCountryFlags=data.candidate_country_flags||{land:{},kontinent:{},region:{},religion:{}};
  const ALL='__alle__';
  const percent=n=>Number(n||0).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' %';
  const signed=n=>(Number(n)>=0?'+':'')+Number(n||0).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' pp';
  const norm=s=>String(s).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  const clear=node=>{while(node.firstChild)node.removeChild(node.firstChild);};
  const el=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
  // Regions-/Religionsgruppen: immer zunaechst pro Nationalitaet auswerten.
  // Das Waehler-Minimum gilt pro Herkunftsland, das Bewerber-Minimum pro
  // Bewerberland. Beide Dimensionen werden getrennt gleichgewichtet gemittelt:
  // zuerst ueber Waehlerlaender, danach ueber Bewerberlaender. So dominiert
  // z.B. die Tuerkei nicht aufgrund ihrer vielen Waehler oder Bewerbungen.
  const rateFields=['trefferquote_gruppe_pct','trefferquote_rest_pct',
                    'punkteanteil_gruppe_pct','punkteanteil_rest_pct'];
  const groupedType=type=>type==='region'||type==='religion';
  const candidateLandCount=code=>Number((candidateCounts.land||{})[code]||0);
  const memberKey=type=>type==='religion'?'religions':'regions';

  // Fuer Waehlerregion/-religion: jede zulaessige Herkunftsnationalitaet
  // traegt mit 1/N zu jeder vergleichbaren Zielquote bei. Fehlt in einer
  // Nationalitaet ein Ziel, ist dessen beobachtete Quote dort 0 %.
  function equalVoterCountries(targetType){
    const eligibleNations=new Map();
    for(const row of (matrices['land_zu_'+targetType]||[])){
      const code=row.waehler_gruppe, country=data.countries[code];
      if(!country || !(country[memberKey(state.source)]||[]).length ||
         Number(row.waehler_einmalig)<state.minUsers)continue;
      if(!eligibleNations.has(code))eligibleNations.set(code,{
        code:code,memberships:country[memberKey(state.source)],
        users:Number(row.waehler_einmalig),
        totalUsers:Number(row.waehler_einmalig_insgesamt),
        participations:Number(row.wahlteilnahmen_gruppe),byTarget:new Map()
      });
      if(targetType!=='land' || candidateLandCount(row.bewerber_ziel)>=state.minCandidates){
        eligibleNations.get(code).byTarget.set(row.bewerber_ziel,row);
      }
    }
    const groups=new Map();
    for(const nation of eligibleNations.values()){
      for(const groupCode of nation.memberships){
        if(!groups.has(groupCode))groups.set(groupCode,{
          countries:[],users:0,totalUsers:0,participations:0,targets:new Map(),targetFlags:new Map()
        });
        const group=groups.get(groupCode);
        group.countries.push(nation.code);
        group.users+=nation.users;
        group.totalUsers+=nation.totalUsers;
        group.participations+=nation.participations;
        for(const [targetCode,row] of nation.byTarget){
          if(!group.targets.has(targetCode))group.targets.set(targetCode,[0,0,0,0]);
          if(groupedType(targetType)){
            if(!group.targetFlags.has(targetCode))group.targetFlags.set(targetCode,new Set());
            for(const countryCode of (row.ziel_laender||[]))group.targetFlags.get(targetCode).add(countryCode);
          }
          const values=group.targets.get(targetCode);
          rateFields.forEach((key,i)=>{values[i]+=Number(row[key]||0);});
        }
      }
    }
    const result=[];
    for(const [groupCode,group] of groups){
      const n=group.countries.length;
      if(!n)continue;
      const countries=group.countries.slice().sort((a,b)=>
        name('land',a).localeCompare(name('land',b),'de'));
      for(const [targetCode,values] of group.targets){
        const g=values[0]/n,r=values[1]/n,pg=values[2]/n,pr=values[3]/n;
        result.push({
          waehler_gruppe:groupCode,bewerber_ziel:targetCode,
          waehler_einmalig:group.users,
          waehler_einmalig_insgesamt:group.totalUsers,
          wahlteilnahmen_gruppe:group.participations,
          region_anzahl_laender:n,region_laender:countries,
          ziel_laender:[...(group.targetFlags.get(targetCode)||[])],
          trefferquote_gruppe_pct:g,trefferquote_rest_pct:r,
          differenz_prozentpunkte:g-r,
          punkteanteil_gruppe_pct:pg,punkteanteil_rest_pct:pr,
          punkte_differenz_prozentpunkte:pg-pr
        });
      }
    }
    return result;
  }

  // Fuer Bewerberregion/-religion: die separat ermittelten Quoten jeder
  // Bewerbernationalitaet zu einem gleichgewichteten Mittel zusammenfassen.
  // Nur Laender mit mindestens minCandidates Bewerbungen kommen hinein;
  // fehlende Vergleichsdaten werden nicht als eigene Nation erfunden.
  function equalApplicantCountries(countryRows){
    const groups=new Map();
    for(const row of countryRows){
      const countryCode=row.bewerber_ziel;
      const country=data.countries[countryCode];
      if(!country || candidateLandCount(countryCode)<state.minCandidates)continue;
      for(const targetCode of (country[memberKey(state.target)]||[])){
        const key=String(row.waehler_gruppe)+'|'+targetCode;
        if(!groups.has(key))groups.set(key,{
          sourceRow:row,sourceCode:row.waehler_gruppe,targetCode:targetCode,
          values:[0,0,0,0],countries:new Set()
        });
        const entry=groups.get(key);
        if(entry.countries.has(countryCode))continue;
        entry.countries.add(countryCode);
        rateFields.forEach((field,i)=>{entry.values[i]+=Number(row[field]||0);});
      }
    }
    const result=[];
    for(const entry of groups.values()){
      const n=entry.countries.size;
      if(!n)continue;
      const [g,r,pg,pr]=entry.values.map(value=>value/n);
      const countryCodes=[...entry.countries].sort((a,b)=>name('land',a).localeCompare(name('land',b),'de'));
      result.push({
        ...entry.sourceRow,bewerber_ziel:entry.targetCode,
        ziel_laender:countryCodes,ziel_anzahl_laender:n,
        trefferquote_gruppe_pct:g,trefferquote_rest_pct:r,
        differenz_prozentpunkte:g-r,
        punkteanteil_gruppe_pct:pg,punkteanteil_rest_pct:pr,
        punkte_differenz_prozentpunkte:pg-pr
      });
    }
    return result;
  }

  const rows=()=>{
    // Bei Zielregion/-religion immer von den Einzel-Nationalitaeten starten,
    // sonst waere das Bewerberminimum nur auf die Gesamtregion angewandt.
    const targetIsGroup=groupedType(state.target);
    const original=groupedType(state.source)
      ? equalVoterCountries(targetIsGroup?'land':state.target)
      : [...(matrices[state.source+'_zu_'+(targetIsGroup?'land':state.target)]||[])];
    // In Waehler->Bewerber "Alle" als Gesamtgruppe einbeziehen.
    // Pro Bewerberposition und Wahlteilnahme normalisiert, mit abstimmungs-
    // spezifischer Zufallserwartung bei gleich verteilter Praeferenzwahl.
    if(state.direction==='forward')original.push(...(allMatrices[targetIsGroup?'land':state.target]||[]));
    if(targetIsGroup)return equalApplicantCountries(original).filter(row=>
      Number(row.waehler_einmalig)>=state.minUsers);
    return original.filter(row=>Number(row.waehler_einmalig)>=state.minUsers &&
      Number((candidateCounts[state.target]||{})[row.bewerber_ziel]||0)>=state.minCandidates);
  };
  const reverseMode=()=>state.direction==='reverse';
  const pickerType=()=>reverseMode()?state.target:state.source;
  const itemType=()=>reverseMode()?state.source:state.target;
  const pickerField=()=>reverseMode()?'bewerber_ziel':'waehler_gruppe';
  const itemField=()=>reverseMode()?'waehler_gruppe':'bewerber_ziel';
  const name=(type,code)=>code===ALL?'Alle':type==='land' ? ((data.countries[code]||{}).name||code)
    : type==='region' ? ((data.regions||{})[code]||code)
    : type==='religion' ? ((data.religions||{})[code]||code)
    : ((data.continents||{})[code]||code);
  function flag(type,code){
    const box=el('span','wv-flag');
    if(code===ALL){box.appendChild(el('span','wv-placeholder-symbol','◎'));}
    else if(type==='land' && /^[a-z]{2}$/.test(code)){
      const img=el('img');img.src='flag-icons/flags/4x3/'+code+'.svg';img.alt='';img.loading='lazy';
      img.onerror=()=>{clear(box);box.appendChild(el('span','wv-placeholder-symbol','◈'));};
      box.appendChild(img);
    } else box.appendChild(el('span','wv-placeholder-symbol','◈'));
    return box;
  }
  const metricKeys=()=>state.metric==='hit'
    ?['differenz_prozentpunkte','trefferquote_gruppe_pct','trefferquote_rest_pct']
    :['punkte_differenz_prozentpunkte','punkteanteil_gruppe_pct','punkteanteil_rest_pct'];
  const isAll=()=>!reverseMode() && state.group===ALL;
  const delta=row=>Number(row[metricKeys()[0]]||0);
  const filtered=()=>rows().filter(row=>row[pickerField()]===state.group);
  const sorted=list=>list.sort((a,b)=>delta(b)-delta(a)||name(itemType(),a[itemField()]).localeCompare(name(itemType(),b[itemField()]),'de'));
  function pickerOptions(){
    const groups=new Map();
    rows().forEach(row=>{
      const code=row[pickerField()];
      if(!groups.has(code))groups.set(code,{
        code:code,users:row.waehler_einmalig,
        candidates:Number((candidateCounts[state.target]||{})[code]||0),
        candidateCountries:new Set()
      });
      if(reverseMode() && groupedType(state.target)){
        for(const countryCode of (row.ziel_laender||[]))groups.get(code).candidateCountries.add(countryCode);
      }
    });
    const result=[...groups.values()];
    if(reverseMode() && groupedType(state.target)){
      result.forEach(group=>{
        group.candidates=[...group.candidateCountries].reduce((total,code)=>
          total+candidateLandCount(code),0);
      });
    }
    result.sort((a,b)=>reverseMode()
      ? (b.candidates-a.candidates)||name(pickerType(),a.code).localeCompare(name(pickerType(),b.code),'de')
      : (b.users-a.users)||name(pickerType(),a.code).localeCompare(name(pickerType(),b.code),'de'));
    // "Alle" unabhaengig von der Waehlertyp-Auswahl stets ganz oben.
    if(!reverseMode()){
      const index=result.findIndex(item=>item.code===ALL);
      if(index>=0)result.unshift(...result.splice(index,1));
    }
    return result;
  }
  function closeMenu(){menu.classList.remove('wv-open');trigger.setAttribute('aria-expanded','false');}
  function updateTrigger(){
    clear(trigger);
    if(state.group!==null){trigger.appendChild(flag(pickerType(),state.group));trigger.appendChild(el('span','wv-group-name',name(pickerType(),state.group)));}
    else trigger.appendChild(el('span','wv-group-name','Keine Gruppe verfügbar'));
    trigger.appendChild(el('span','wv-arrow'));
  }
  function renderOptions(){
    clear(groupOptions);
    const find=norm(menuSearch.value);
    const options=pickerOptions().filter(item=>norm(name(pickerType(),item.code)).includes(find)||String(item.code).toLowerCase().includes(find));
    if(!options.length){groupOptions.appendChild(el('div','wv-empty','Keine passenden Gruppen'));return;}
    options.forEach(item=>{
      const button=el('button','wv-menu-option');button.type='button';button.setAttribute('role','option');
      button.setAttribute('aria-selected',String(item.code===state.group));
      button.appendChild(flag(pickerType(),item.code));button.appendChild(el('span',null,name(pickerType(),item.code)));
      button.appendChild(el('span','wv-option-meta',reverseMode()?item.candidates+' Bewerber':item.users+' Wähler'));
      button.addEventListener('click',()=>{state.group=item.code;closeMenu();updateTrigger();render();});
      groupOptions.appendChild(button);
    });
  }
  function resetPicker(preferred){
    const groups=pickerOptions();
    const values=new Set(groups.map(g=>g.code));
    state.group=values.has(preferred)?preferred:(reverseMode()&&values.has('de')?'de':(groups.length?groups[0].code:null));
    menuSearch.value='';closeMenu();renderOptions();render();
  }
  function renderContext(){
    const context=$('wv-group-context'),heading=$('wv-context-title'),flags=$('wv-context-flags');
    clear(heading);clear(flags);
    flags.classList.toggle('wv-context-flags--all',isAll());
    if(state.group===null){context.hidden=true;return;}
    const type=pickerType();
    const selection=name(type,state.group);
    // Die Beschriftung benennt ausdruecklich die aktuell ausgewaehlte Dimension.
    // Das gilt in beiden Blickrichtungen und auch fuer gemischte Regionen.
    const dimension=type==='land'?'der Nation ':type==='kontinent'?'dem Kontinent ':type==='religion'?'der Religionsgruppe ':'der Region ';
    const prefix=reverseMode()
      ? 'Wahlverhalten hinsichtlich Bewerbern aus '+dimension
      : 'Wahlverhalten für Wähler aus '+dimension;
    if(isAll()){
      heading.appendChild(el('strong',null,'Wahlverhalten aller Wähler'));
    }else{
      heading.appendChild(document.createTextNode(prefix));
      heading.appendChild(el('strong',null,selection));
    }

    // Laender NICHT pauschal anhand der Region-/Kontinentdefinition auflisten:
    // - Waehler: nur Nationalitaeten mit auswertbaren Wahlteilnahmen.
    // - Waehlerregionen: ausschliesslich Nationalitaeten, die das aktive
    //   Waehlerminimum erreichen und in das gleichgewichtete Mittel eingehen.
    // - Bewerber: nur Nationalitaeten in den akzeptierten Bewerber-PDFs.
    const available=isAll()
      ? (data.all_country_flags||[])
      : reverseMode()
      ? (groupedType(state.target)
        ? rows().filter(row=>row.bewerber_ziel===state.group).flatMap(row=>row.ziel_laender||[])
        : ((candidateCountryFlags[state.target]||{})[state.group]||[]))
      : (state.source==='region' || state.source==='religion')
        ? ((rows().find(row=>row.waehler_gruppe===state.group)||{}).region_laender||[])
        : ((sourceCountryFlags[state.source]||{})[state.group]||[]);
    const countries=[...new Set(available)].filter(code=>/^[a-z]{2}$/.test(code) && !!data.countries[code]);
    countries.sort((a,b)=>name('land',a).localeCompare(name('land',b),'de'));
    flags.setAttribute('aria-label',countries.length+' tatsächlich vertretene '+(reverseMode()?'Bewerbernationalitäten':'Wählernationalitäten'));
    // Gesamtansicht: maximal 20 Flaggen je Zeile, ausgeglichen (z.B. 39 = 20 + 19).
    // Andere Ansichten behalten die bisherige Aufteilung mit maximal 10.
    const maxPerLine=isAll()?20:10;
    const lineCount=Math.ceil(countries.length/maxPerLine);
    const base=lineCount?Math.floor(countries.length/lineCount):0;
    const extra=lineCount?countries.length%lineCount:0;
    let offset=0;
    for(let line=0;line<lineCount;line++){
      const flagLine=el('span','wv-context-flag-line');
      const count=base+(line<extra?1:0);
      for(const code of countries.slice(offset,offset+count)){
        const item=flag('land',code);
        const countryName=name('land',code);
        item.title=countryName;
        item.setAttribute('role','img');
        item.setAttribute('aria-label',countryName);
        flagLine.appendChild(item);
      }
      flags.appendChild(flagLine);
      offset+=count;
    }
    context.hidden=false;
  }
  function render(){
    updateTrigger();
    renderContext();
    const backwards=reverseMode();
    const outType=itemType();
    $('wv-group-label').textContent=backwards?'Bewerbergruppe auswählen':'Wählergruppe auswählen';
    $('wv-chart-first-heading').textContent=backwards?'Wähler':'Bewerber';
    $('wv-chart-rate-heading').textContent='Abweichung';
    $('wv-chart-value-heading').textContent='Differenz (pp)';
    const list=sorted(filtered());
    const chart=$('wv-chart-list');clear(chart);
    if(!list.length){chart.appendChild(el('div','wv-empty','Keine vergleichbaren Werte für diese Auswahl.'));return;}
    const maximum=Math.max(1,...list.map(row=>Math.abs(delta(row))));
    const keys=metricKeys();const fragment=document.createDocumentFragment();
    list.forEach(row=>{
      const value=delta(row),item=el('div','wv-chart-row');item.setAttribute('role','listitem');
      const country=el('span','wv-target-cell'+(outType==='region'||outType==='religion'?' wv-target-cell--region':''));
      if(outType==='region'||outType==='religion'){
        country.appendChild(el('span','wv-target-name',name(outType,row[itemField()])));
        const icons=el('span','wv-region-row-flags');
        const regionCodes=backwards ? (row.region_laender||[]) : (row.ziel_laender||[]);
        const uniqueCodes=[...new Set(regionCodes)].filter(code=>/^[a-z]{2}$/.test(code)&&data.countries[code]);
        uniqueCodes.sort((a,b)=>name('land',a).localeCompare(name('land',b),'de'));
        const lineCount=Math.ceil(uniqueCodes.length/8);
        const base=lineCount?Math.floor(uniqueCodes.length/lineCount):0;
        const extra=lineCount?uniqueCodes.length%lineCount:0;
        let offset=0;
        for(let line=0;line<lineCount;line++){
          const rowFlags=el('span','wv-region-flag-line');
          const count=base+(line<extra?1:0);
          for(const code of uniqueCodes.slice(offset,offset+count)){
            const f=flag('land',code);f.title=name('land',code);rowFlags.appendChild(f);
          }
          icons.appendChild(rowFlags);offset+=count;
        }
        icons.setAttribute('aria-label',uniqueCodes.length+' berücksichtigte Länder');
        country.appendChild(icons);
      } else {
        country.appendChild(flag(outType,row[itemField()]));
        country.appendChild(el('span','wv-target-name',name(outType,row[itemField()])));
      }
      item.appendChild(country);
      const bar=el('div','wv-diverging'),fill=el('div','wv-diverging-fill '+(value<0?'wv-negative':'wv-positive'));
      if(value!==0)fill.style.width=Math.max(.5,Math.abs(value)/maximum*50)+'%';else fill.style.display='none';
      bar.appendChild(fill);item.appendChild(bar);
      item.appendChild(el('span','wv-delta '+(value>0?'wv-positive':value<0?'wv-negative':'wv-neutral'),signed(value)));
      item.title=name(outType,row[itemField()])+': '+signed(value)+'; '+(isAll()?('pro Bewerber: gewählt '+percent(row[keys[1]])+' / Gleichverteilung '+percent(row[keys[2]])+'; '+row.waehler_einmalig+' unterschiedliche Wähler, '+row.wahlteilnahmen_gruppe+' Wahlteilnahmen'+(row.bewerber_positionen ? '; '+row.bewerber_positionen+' Bewerberpositionen':'')+(row.bewerber_chancen ? '; '+row.bewerber_chancen+' Bewerber-Wahlteilnahme-Chancen':'')) : ('Wählergruppe: '+percent(row[keys[1]])+'; andere Wähler: '+percent(row[keys[2]])+'; '+row.waehler_einmalig+' unterschiedliche Wähler, '+row.wahlteilnahmen_gruppe+' Wahlteilnahmen'))+((!isAll()&&(state.source==='region'||state.source==='religion')) ? '; Mittelwert aus '+row.region_anzahl_laender+' gleichgewichteten Wählernationalitäten (je ≥'+state.minUsers+' Wähler)' : '')+(groupedType(state.target) ? '; Mittelwert aus '+row.ziel_anzahl_laender+' gleichgewichteten Bewerbernationalitäten (je ≥'+state.minCandidates+' Bewerber)' : '');
      fragment.appendChild(item);
    });
    chart.appendChild(fragment);
  }
  function setDirection(direction){
    if(state.direction===direction)return;
    state.direction=direction;
    forward.classList.toggle('wv-active',direction==='forward');reverse.classList.toggle('wv-active',direction==='reverse');
    forward.setAttribute('aria-pressed',String(direction==='forward'));reverse.setAttribute('aria-pressed',String(direction==='reverse'));
    resetPicker(null);
  }
  function setMetric(which){
    state.metric=which;
    metricHit.classList.toggle('wv-active',which==='hit');metricPoints.classList.toggle('wv-active',which==='points');
    metricHit.setAttribute('aria-pressed',String(which==='hit'));metricPoints.setAttribute('aria-pressed',String(which==='points'));
    render();
  }
  // Umfangreicher PDF-Bericht: exakt dieselbe Wahlwahrscheinlichkeits-Kennzahl
  // und dieselben Regions-/Religionsmittel wie in der interaktiven Ansicht.
  // Fuer den Export immer Mindestgrenzen 2 Waehler / 1 Bewerber verwenden.
  // Die vollstaendigen Zaehlstatistiken bleiben unabhaengig davon ungefiltert.
  function wvReportRows(sourceType,targetType){
    const old={...state};
    try {
      state.source=sourceType;
      state.target=targetType;
      state.direction='forward';
      state.metric='hit';
      state.minUsers=2;
      state.minCandidates=1;
      return rows();
    } finally {Object.assign(state,old);}
  }
  function wvSortedCounts(counts){
    return Object.entries(counts||{}).filter(([code,n])=>data.countries[code]&&Number(n)>0)
      .map(([code,n])=>({code,name:name('land',code),count:Number(n)}))
      .sort((a,b)=>b.count-a.count||a.name.localeCompare(b.name,'de'));
  }
  const wvCompact=(r,dimension,field)=>({
    code:r[field],name:name(dimension,r[field]),
    delta:Number(r.differenz_prozentpunkte||0),
    rate:Number(r.trefferquote_gruppe_pct||0),
    reference:Number(r.trefferquote_rest_pct||0)
  });
  function wvGroupPair(groupCode,leftMatrix,rightMatrix,leftType,rightType){
    const byDelta=(a,b)=>b.delta-a.delta||a.name.localeCompare(b.name,'de');
    const leftRows=leftMatrix.filter(r=>r.waehler_gruppe===groupCode);
    const rightRows=rightMatrix.filter(r=>r.bewerber_ziel===groupCode);
    const asCountries=(codes)=>[...new Set(codes)]
      .filter(code=>/^[a-z]{2}$/.test(code)&&data.countries[code])
      .map(code=>({code,name:name('land',code)}))
      .sort((a,b)=>a.name.localeCompare(b.name,'de'));
    // Ausschliesslich die Herkunfts-/Bewerbernationalitaeten, die in den
    // angezeigten Vergleichszeilen tatsaechlich verwendet wurden.
    const voterCountries=asCountries(leftRows.flatMap(r=>r.region_laender||r.waehler_laender||[]));
    const applicantCountries=asCountries(rightRows.flatMap(r=>r.ziel_laender||[]));
    return {
      code:groupCode,
      name:name(leftType,groupCode),
      voterCountries,applicantCountries,
      left:leftRows.map(r=>wvCompact(r,'land','bewerber_ziel')).sort(byDelta),
      right:rightRows.map(r=>wvCompact(r,'land','waehler_gruppe')).sort(byDelta)
    };
  }
  function wvBuildReport(){
    const voters=wvSortedCounts(data.voter_counts||{});
    const applicants=wvSortedCounts(candidateCounts.land||{});
    const nationRows=wvReportRows('land','land');
    const allRows=nationRows.filter(r=>r.waehler_gruppe===ALL)
      .map(r=>({name:name('land',r.bewerber_ziel),code:r.bewerber_ziel,
        delta:Number(r.differenz_prozentpunkte||0),
        rate:Number(r.trefferquote_gruppe_pct||0),
        reference:Number(r.trefferquote_rest_pct||0),
        candidacies:Number(r.bewerber_positionen||0),
        opportunities:Number(r.bewerber_chancen||0)}))
      .sort((a,b)=>b.delta-a.delta||a.name.localeCompare(b.name,'de'));
    const linkedNationRows=nationRows.filter(r=>r.waehler_gruppe!==ALL);
    const voterTop=voters.filter(r=>r.count>=2).slice(0,10);
    const applicantTop=applicants.slice(0,10);
    const topCodes=[...new Set([...voterTop,...applicantTop].map(x=>x.code))];
    const nationalPairs=topCodes.map(code=>({
      ...wvGroupPair(code,linkedNationRows,linkedNationRows,'land','land'),kind:'Nation',
      voters:Number((data.voter_counts||{})[code]||0),
      applicants:Number((candidateCounts.land||{})[code]||0),
      topVoter:voterTop.some(x=>x.code===code),
      topApplicant:applicantTop.some(x=>x.code===code)
    }));
    const patriotism=linkedNationRows.filter(r=>r.waehler_gruppe===r.bewerber_ziel)
      .map(r=>({name:name('land',r.waehler_gruppe),code:r.waehler_gruppe,
        delta:Number(r.differenz_prozentpunkte||0),
        rate:Number(r.trefferquote_gruppe_pct||0),
        reference:Number(r.trefferquote_rest_pct||0),
        voters:Number((data.voter_counts||{})[r.waehler_gruppe]||0),
        applicants:Number((candidateCounts.land||{})[r.bewerber_ziel]||0)}))
      .sort((a,b)=>b.delta-a.delta||a.name.localeCompare(b.name,'de'));
    // Streuung der Bevorzugung/Ablehnung verschiedener Bewerbernationalitaeten
    // relativ zum jeweiligen Rest der Waehler. Kein Rueckschluss auf Motive.
    const sourceCodes=[...new Set(linkedNationRows.map(r=>r.waehler_gruppe))];
    const racism=sourceCodes.map(code=>{
      const options=linkedNationRows.filter(r=>r.waehler_gruppe===code)
        .map(r=>wvCompact(r,'land','bewerber_ziel'))
        .sort((a,b)=>b.delta-a.delta||a.name.localeCompare(b.name,'de'));
      if(!options.length)return null;
      return {code,name:name('land',code),voters:Number((data.voter_counts||{})[code]||0),
        best:options[0],worst:options[options.length-1],
        spread:Number((options[0].delta-options[options.length-1].delta).toFixed(4)),
        targets:options.length};
    }).filter(Boolean).sort((a,b)=>b.spread-a.spread||a.name.localeCompare(b.name,'de'));
    const regionLeft=wvReportRows('region','land');
    const regionRight=wvReportRows('land','region');
    const continentLeft=wvReportRows('kontinent','land');
    const continentRight=wvReportRows('land','kontinent');
    const religionLeft=wvReportRows('religion','land');
    const religionRight=wvReportRows('land','religion');
    const continents=Object.keys(data.continents||{}).filter(code=>
      (candidateCounts.kontinent||{})[code]||continentLeft.some(r=>r.waehler_gruppe===code));
    const geo=[
      ...continents.map(code=>({
        ...wvGroupPair(code,continentLeft,continentRight,'kontinent','kontinent'),kind:'Kontinent'
      })),
      ...Object.keys(data.regions||{}).map(code=>({
        ...wvGroupPair(code,regionLeft,regionRight,'region','region'),kind:'Region'
      }))
    ];
    const religions=Object.keys(data.religions||{}).map(code=>({
      ...wvGroupPair(code,religionLeft,religionRight,'religion','religion'),kind:'Religion'
    }));
    return {schema:1,created:new Date().toISOString(),
      methodology:'Wahlwahrscheinlichkeit, Mindestanzahl 2 Wähler und 1 Bewerber je nationaler Vergleichsgruppe; Prozentpunktdifferenz. Regionen/Religionen: pro Nationalität gleiches Gewicht. Zähltabellen vollständig.',
      quality:data.quality||{},
      sections:{counts:{voters,applicants},
        all:allRows,
        nations:{topVoters:voterTop,topApplicants:applicantTop,pairs:nationalPairs},
        geography:geo,religions,
        patriotism,racism}};
  }
  // Die Flaggen liegen lokal als SVG vor. FPDF unterstützt jedoch nur Rastergrafiken.
  // Pro Export in kleinen transparenten PNGs rendern; das PDF selbst entsteht in PHP.
  async function wvPdfFlags(){
    const codes=Object.keys(data.countries||{}).filter(code=>/^[a-z]{2}$/.test(code));
    const converted={};
    const queue=[...codes];
    async function worker(){
      while(queue.length){
        const code=queue.shift();
        try {
          const uri=await new Promise((resolve,reject)=>{
            const img=new Image();
            img.onload=()=>{
              try {
                const canvas=document.createElement('canvas');
                canvas.width=48;canvas.height=36;
                const ctx=canvas.getContext('2d');
                if(!ctx)throw new Error('Canvas nicht verfügbar');
                ctx.clearRect(0,0,48,36);
                ctx.drawImage(img,0,0,48,36);
                resolve(canvas.toDataURL('image/png'));
              }catch(error){reject(error);}
            };
            img.onerror=()=>reject(new Error('Flagge nicht verfügbar'));
            img.src='flag-icons/flags/4x3/'+code+'.svg';
          });
          if(uri.length<=55000)converted[code]=uri;
        }catch(error){
          // FPDF zeichnet ersatzweise ein kleines ISO-Kennzeichen.
        }
      }
    }
    await Promise.all(Array.from({length:Math.min(12,queue.length)},()=>worker()));
    return converted;
  }

  async function wvExportPdf(){
    const button=$('wv-export-button');
    if(!button||button.disabled)return;
    const caption=button.querySelector('span'),oldCaption=caption.textContent;
    button.disabled=true;caption.textContent='PDF wird erstellt ...';
    try {
      const report=wvBuildReport();
      report.flags=await wvPdfFlags();
      const form=new FormData();
      form.append('wv_action','pdf');
      form.append('wv_token',document.querySelector('#wv-refresh-form input[name="wv_token"]').value);
      form.append('wv_report',JSON.stringify(report));
      const response=await fetch('Wahlverhalten.php',{method:'POST',body:form,credentials:'same-origin'});
      if(!response.ok){throw new Error((await response.text()).slice(0,350)||('HTTP '+response.status));}
      const blob=await response.blob();
      if(!blob.type.includes('pdf'))throw new Error('Server hat kein PDF zurueckgegeben.');
      const url=URL.createObjectURL(blob),link=document.createElement('a');
      const date=new Date().toISOString().slice(0,10);
      link.href=url;link.download='WEH_Wahlverhalten_'+date+'.pdf';
      document.body.appendChild(link);link.click();link.remove();
      setTimeout(()=>URL.revokeObjectURL(url),60000);
    }catch(error){
      alert('PDF-Erstellung fehlgeschlagen: '+error.message);
      console.error('PDF-Export:',error);
    }finally{button.disabled=false;caption.textContent=oldCaption;}
  }

  sourceSelect.addEventListener('change',()=>{state.source=sourceSelect.value;resetPicker(state.group);});
  targetSelect.addEventListener('change',()=>{state.target=targetSelect.value;resetPicker(state.group);});
  forward.addEventListener('click',()=>setDirection('forward'));reverse.addEventListener('click',()=>setDirection('reverse'));
  metricHit.addEventListener('click',()=>setMetric('hit'));metricPoints.addEventListener('click',()=>setMetric('points'));
  minUsersSelect.addEventListener('change',()=>{state.minUsers=Number(minUsersSelect.value);resetPicker(state.group);});
  minCandidatesSelect.addEventListener('change',()=>{state.minCandidates=Number(minCandidatesSelect.value);resetPicker(state.group);});
  trigger.addEventListener('click',()=>{const open=menu.classList.toggle('wv-open');trigger.setAttribute('aria-expanded',String(open));if(open){renderOptions();menuSearch.focus();menuSearch.select();}});
  menuSearch.addEventListener('input',renderOptions);
  menuSearch.addEventListener('keydown',e=>{if(e.key==='Escape'){closeMenu();trigger.focus();}else if(e.key==='ArrowDown'){e.preventDefault();const first=groupOptions.querySelector('button');if(first)first.focus();}});
  groupOptions.addEventListener('keydown',e=>{
    if(e.key==='Escape'){e.preventDefault();closeMenu();trigger.focus();return;}
    if(!['ArrowDown','ArrowUp'].includes(e.key))return;
    const items=[...groupOptions.querySelectorAll('button')],i=items.indexOf(document.activeElement),next=i+(e.key==='ArrowDown'?1:-1);
    if(items[next]){e.preventDefault();items[next].focus();}
  });
  document.addEventListener('click',e=>{if(!trigger.contains(e.target)&&!menu.contains(e.target))closeMenu();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&menu.classList.contains('wv-open')){closeMenu();trigger.focus();}});
  const exportButton=$('wv-export-button');
  if(exportButton)exportButton.addEventListener('click',wvExportPdf);
  $('wv-refresh-form').addEventListener('submit',()=>{const b=$('wv-refresh-button');b.disabled=true;b.querySelector('span').textContent='Bewerber-JSON wird aktualisiert ...';});
  resetPicker(null);
})();
</script>
<?php endif; ?>
