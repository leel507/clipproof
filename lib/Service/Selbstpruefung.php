<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\BackgroundJob\OrdnerJob;
use OCA\Clipproof\BackgroundJob\StuendlichJob;
use OCA\Clipproof\Db\OrdnerMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Selbsttest nach der Installation: Ist alles richtig eingerichtet, und
 * was fehlt? Nur lesen, nichts ändern. Gibt nie Tokens aus.
 *
 * Jede Zeile ist ok, Hinweis (läuft, aber ansehen) oder Fehler (so geht
 * etwas nicht). Ausgabe englisch wie bei occ üblich, Zeiten in der
 * Zeitzone der Instanz.
 */
class Selbstpruefung {
	public const OK = 'ok';
	public const HINWEIS = 'hinweis';
	public const FEHLER = 'fehler';

	public const TABELLEN = [
		'clipproof_reviews', 'clipproof_versions', 'clipproof_guests',
		'clipproof_comments', 'clipproof_submissions',
		'clipproof_loeschungen', 'clipproof_ordner',
	];

	/** Hintergrundjobs mit Anzeigename und Abstand in Sekunden. */
	private const JOBS = [
		OrdnerJob::class => ['Project folders', 5 * 60],
		StuendlichJob::class => ['Hourly (archive, retention)', 60 * 60],
	];

	/** @var list<array{stufe: string, text: string}> */
	private array $zeilen = [];

	public function __construct(
		private IAppManager $apps,
		private IAppConfig $appConfig,
		private IConfig $config,
		private Tabellen $tabellen,
		private IJobList $jobs,
		private EinbettungService $einbettung,
		private OrdnerMapper $ordner,
		private ITimeFactory $time,
	) {
	}

	/** @return list<array{stufe: string, text: string}> */
	public function pruefen(): array {
		$this->zeilen = [];
		$this->app();
		$this->hintergrund();
		$this->adresse();
		$this->projektordner();
		$this->einbettung();
		return $this->zeilen;
	}

	private function app(): void {
		$soll = $this->apps->getAppVersion(Application::APP_ID);
		$ist = $this->appConfig->getValueString(Application::APP_ID, 'installed_version', '');
		if ($ist === $soll) {
			$this->ok("App version $soll, database schema matches");
		} else {
			$this->fehler("App files are version $soll, the database is at " . ($ist !== '' ? $ist : 'none') . '. Fix: occ upgrade');
		}

		$fehlend = $this->tabellen->fehlend(self::TABELLEN);
		if ($fehlend === []) {
			$this->ok('All ' . count(self::TABELLEN) . ' tables present');
		} else {
			$this->fehler('Missing tables: oc_' . implode(', oc_', $fehlend) . '. Fix: occ app:disable clipproof, then occ app:enable clipproof');
		}

		if (version_compare(PHP_VERSION, '8.3.0', '>=')) {
			$this->ok('PHP ' . PHP_VERSION);
		} else {
			$this->fehler('PHP ' . PHP_VERSION . ', at least 8.3 is required');
		}
	}

	private function hintergrund(): void {
		$modus = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		if ($modus === 'cron') {
			$this->ok('Background jobs run via cron');
		} else {
			$this->fehler("Background jobs run via \"{$modus}\", not cron. Project folders and the archive then only run when someone opens a page. Fix: occ background:cron");
		}

		$jetzt = $this->time->getTime();
		$letzterCron = $this->zahl('core', 'lastcron');
		if ($modus !== 'cron') {
			// Zeitpunkt sagt dann nichts über Cron; der Fehler steht schon oben.
		} elseif ($letzterCron === 0) {
			$this->hinweis('Cron has never run');
		} elseif ($jetzt - $letzterCron > 15 * 60) {
			$this->fehler('Cron last ran ' . $this->wann($letzterCron) . ', more than 15 minutes ago');
		} else {
			$this->ok('Cron last ran ' . $this->wann($letzterCron));
		}

		foreach (self::JOBS as $klasse => [$name, $abstand]) {
			if (!$this->jobs->has($klasse, null)) {
				$this->fehler("Background job \"{$name}\" is missing. Fix: occ app:disable clipproof, then occ app:enable clipproof");
				continue;
			}
			$zuletzt = 0;
			foreach ($this->jobs->getJobsIterator($klasse, 1, 0) as $job) {
				$zuletzt = $job->getLastRun();
			}
			if ($zuletzt === 0) {
				$this->hinweis("Background job \"{$name}\" is registered but has not run yet (normal right after installation)");
			} elseif ($jetzt - $zuletzt > 3 * $abstand + 15 * 60) {
				$this->hinweis("Background job \"{$name}\" last ran " . $this->wann($zuletzt) . ', longer ago than expected');
			} else {
				$this->ok("Background job \"{$name}\" last ran " . $this->wann($zuletzt));
			}
		}
	}

	/** Links, die im Cron entstehen (Projektordner), nehmen overwrite.cli.url. */
	private function adresse(): void {
		$url = trim($this->config->getSystemValueString('overwrite.cli.url', ''));
		if ($url === '') {
			$this->fehler('overwrite.cli.url is not set. Client links created in the background (project folders) would be wrong');
		} elseif (!str_starts_with($url, 'https://') || preg_match('#^https://(localhost|127\.|\[::1\])#', $url) === 1) {
			$this->fehler("overwrite.cli.url is \"{$url}\". Client links created in the background need the public https address");
		} else {
			$this->ok("Client links created in the background start with {$url}");
		}
	}


	private function projektordner(): void {
		$aktive = $this->ordner->findAktive();
		$gestoert = array_values(array_filter($aktive, fn ($o) => $o->getFehler() !== null && $o->getFehler() !== ''));
		if ($gestoert === []) {
			$this->ok(count($aktive) . ' project folder(s) enabled, none reports a problem');
			return;
		}
		$this->hinweis(count($aktive) . ' project folder(s) enabled, ' . count($gestoert) . (count($gestoert) === 1 ? ' reports' : ' report') . ' a problem:');
		foreach (array_slice($gestoert, 0, 10) as $o) {
			$this->hinweis('  ' . $o->getKontoUid() . '/' . $o->getPfad() . ': ' . self::fehlerKlartext((string)$o->getFehler()));
		}
	}

	private function einbettung(): void {
		$origins = $this->einbettung->origins();
		if ($origins === []) {
			$this->ok('Client page cannot be embedded (default)');
		} else {
			$this->ok('Client page may be embedded by ' . implode(', ', $origins));
		}
	}

	private function zahl(string $app, string $schluessel): int {
		try {
			return $this->appConfig->getValueInt($app, $schluessel, 0);
		} catch (\Throwable) {
			try {
				return (int)$this->appConfig->getValueString($app, $schluessel, '0');
			} catch (\Throwable) {
				return 0;
			}
		}
	}

	private function wann(int $zeit): string {
		$zone = $this->config->getSystemValueString('default_timezone', 'UTC');
		try {
			$tz = new \DateTimeZone($zone !== '' ? $zone : 'UTC');
		} catch (\Throwable) {
			$tz = new \DateTimeZone('UTC');
		}
		return 'on ' . (new \DateTimeImmutable('@' . $zeit))->setTimezone($tz)->format('Y-m-d H:i') . ' (' . $tz->getName() . ')';
	}

	/** Ordnerfehler sind JSON (englischer Quelltext, Werte, Datei), ältere Einträge Klartext. */
	public static function fehlerKlartext(string $gespeichert): string {
		$f = json_decode($gespeichert, true);
		if (!is_array($f) || !isset($f['m']) || !is_string($f['m'])) {
			return $gespeichert;
		}
		$text = vsprintf($f['m'], array_map('strval', (array)($f['w'] ?? [])));
		return ($f['d'] ?? '') !== '' ? $f['d'] . ': ' . $text : $text;
	}

	private function ok(string $text): void {
		$this->zeilen[] = ['stufe' => self::OK, 'text' => $text];
	}

	private function hinweis(string $text): void {
		$this->zeilen[] = ['stufe' => self::HINWEIS, 'text' => $text];
	}

	private function fehler(string $text): void {
		$this->zeilen[] = ['stufe' => self::FEHLER, 'text' => $text];
	}
}
