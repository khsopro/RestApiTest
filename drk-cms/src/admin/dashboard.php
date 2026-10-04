<?php
$u = current_user();
$today = date('Y-m-d');
?>
<h1>Hallo <?= e($u['name'] ?: $u['benutzername']) ?>!</h1>

<div class="cards">
    <?php if (has_role('redaktion')): ?>
        <a class="card stat" href="<?= e(url_admin('seiten')) ?>"><strong><?= (int)val('SELECT COUNT(*) FROM seiten') ?></strong>Seiten</a>
        <a class="card stat" href="<?= e(url_admin('news')) ?>"><strong><?= (int)val('SELECT COUNT(*) FROM news WHERE datum >= ?', [date('Y-m-d', strtotime('-30 days'))]) ?></strong>Meldungen (30 Tage)</a>
    <?php endif; ?>
    <?php if (has_role('verwaltung')): ?>
        <a class="card stat" href="<?= e(url_admin('mitglieder')) ?>"><strong><?= (int)val("SELECT COUNT(*) FROM mitglieder WHERE status = 'aktiv'") ?></strong>aktive Mitglieder</a>
        <a class="card stat" href="<?= e(url_admin('mitglieder', '', ['status' => 'foerdernd'])) ?>"><strong><?= (int)val("SELECT COUNT(*) FROM mitglieder WHERE status = 'foerdernd'") ?></strong>Fördermitglieder</a>
        <a class="card stat" href="<?= e(url_admin('unterstuetzer')) ?>"><strong><?= (int)val('SELECT COUNT(*) FROM unterstuetzer') ?></strong>Unterstützer</a>
    <?php endif; ?>
    <?php if (has_role('blutspende')): ?>
        <a class="card stat" href="<?= e(url_admin('blutspende')) ?>"><strong><?= (int)val('SELECT COUNT(*) FROM bs_termine WHERE datum >= ?', [$today]) ?></strong>kommende Blutspendetermine</a>
    <?php endif; ?>
</div>

<div class="grid-2">
    <?php if (has_role('blutspende')): ?>
        <section class="panel">
            <h2>Nächste Blutspendetermine</h2>
            <?php $termine = all('SELECT * FROM bs_termine WHERE datum >= ? ORDER BY datum LIMIT 5', [$today]); ?>
            <?php if (!$termine): ?><p class="muted">Keine Termine geplant. <a href="<?= e(url_admin('blutspende', 'neu')) ?>">Termin anlegen</a></p><?php endif; ?>
            <table>
                <?php foreach ($termine as $t): [$have, $need] = staffing((int)$t['id']); ?>
                    <tr>
                        <td><a href="<?= e(url_admin('blutspende', 'termin', ['id' => $t['id']])) ?>"><?= e(date_de($t['datum'], true)) ?></a></td>
                        <td><?= e($t['ort']) ?></td>
                        <td><?= staffing_badge($have, $need) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </section>
    <?php endif; ?>

    <?php if (has_role('verwaltung')): ?>
        <section class="panel">
            <h2>Geburtstage (nächste 14 Tage)</h2>
            <?php
            $bdays = [];
            foreach (all("SELECT id, vorname, nachname, geburtsdatum FROM mitglieder WHERE geburtsdatum IS NOT NULL AND geburtsdatum <> '' AND status <> 'ausgetreten'") as $r) {
                $ts = strtotime($r['geburtsdatum']);
                if (!$ts) continue;
                $next = strtotime(date('Y') . '-' . date('m-d', $ts));
                if ($next < strtotime($today)) $next = strtotime((date('Y') + 1) . '-' . date('m-d', $ts));
                $diff = (int)round(($next - strtotime($today)) / 86400);
                if ($diff <= 14) $bdays[] = $r + ['next' => $next, 'diff' => $diff, 'alter' => (int)date('Y', $next) - (int)date('Y', $ts)];
            }
            usort($bdays, fn($x, $y) => $x['diff'] <=> $y['diff']);
            ?>
            <?php if (!$bdays): ?><p class="muted">Keine Geburtstage in den nächsten 14 Tagen.</p><?php endif; ?>
            <ul class="plain">
                <?php foreach ($bdays as $b): ?>
                    <li><strong><?= date('d.m.', $b['next']) ?></strong> <a href="<?= e(url_admin('mitglieder', 'bearbeiten', ['id' => $b['id']])) ?>"><?= e(member_name($b)) ?></a>
                        (<?= $b['alter'] ?>)<?= $b['diff'] === 0 ? ' <span class="badge ok">heute</span>' : '' ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($u['mitglied_id']): ?>
        <section class="panel">
            <h2>Meine Einsätze</h2>
            <?php $mine = all("SELECT t.id, t.datum, t.ort, s.aufgabe, " . SQL_EFF_VON . " AS von, " . SQL_EFF_BIS . " AS bis, e.status FROM bs_einteilung e
                JOIN bs_schichten s ON s.id = e.schicht_id JOIN bs_termine t ON t.id = s.termin_id
                WHERE e.mitglied_id = ? AND t.datum >= ? ORDER BY t.datum, von", [$u['mitglied_id'], $today]); ?>
            <?php if (!$mine): ?><p class="muted">Aktuell keine Einsätze eingeplant.</p><?php endif; ?>
            <table>
                <?php foreach ($mine as $r): ?>
                    <tr><td><?= e(date_de($r['datum'], true)) ?></td><td><?= e($r['ort']) ?></td><td><?= e($r['aufgabe']) ?></td>
                        <td><?= e($r['von']) ?>–<?= e($r['bis']) ?></td><td><span class="badge"><?= e($r['status']) ?></span></td></tr>
                <?php endforeach; ?>
            </table>
        </section>
    <?php endif; ?>

    <?php if (has_role('redaktion|oeffentlichkeit')):
        $smNext = all("SELECT * FROM sm_beitraege WHERE status <> 'veroeffentlicht' AND datum BETWEEN ? AND ? ORDER BY datum, uhrzeit LIMIT 6",
            [date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('+7 days'))]); ?>
        <section class="panel">
            <h2>Social Media – nächste 7 Tage</h2>
            <?php if (!$smNext): ?><p class="muted">Nichts geplant. <a href="<?= e(url_admin('social')) ?>">Redaktionsplan öffnen</a></p><?php endif; ?>
            <ul class="plain">
                <?php foreach ($smNext as $p): ?>
                    <li><strong class="<?= $p['datum'] < $today ? 'warn-text' : '' ?>"><?= e(date_de($p['datum'], true)) ?></strong>
                        <a href="<?= e(url_admin('social', 'bearbeiten', ['id' => $p['id']])) ?>"><?= e($p['titel']) ?></a>
                        <span class="badge st-<?= e($p['status']) ?>"><?= e(sm_status()[$p['status']] ?? '') ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if (has_role('redaktion')): ?>
        <section class="panel">
            <h2>Zuletzt bearbeitete Seiten</h2>
            <ul class="plain">
                <?php foreach (all('SELECT id, titel, aktualisiert FROM seiten ORDER BY aktualisiert DESC LIMIT 5') as $p): ?>
                    <li><a href="<?= e(url_admin('seiten', 'bearbeiten', ['id' => $p['id']])) ?>"><?= e($p['titel']) ?></a> <span class="muted"><?= e(datetime_de($p['aktualisiert'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
