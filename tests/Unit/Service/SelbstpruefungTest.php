<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Db\Ordner;
use OCA\Clipproof\Db\OrdnerMapper;
use OCA\Clipproof\Service\EinbettungService;
use OCA\Clipproof\Service\Selbstpruefung;
use OCA\Clipproof\Service\Tabellen;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class SelbstpruefungTest extends TestCase {
	private const JETZT = 1_791_741_600;

	/** @var array<string, mixed> Stellschrauben je Fall */
	private array $s;
	/** @var list<Ordner> */
	private array $ordner = [];
	/** @var list<string> */
	private array $origins = [];

	protected function setUp(): void {
		// Ausgangslage: eine richtig eingerichtete Instanz.
		$this->s = [
			'installiert' => '0.14.0',
			'modus' => 'cron',
			'lastcron' => self::JETZT - 120,
			'lastrun' => self::JETZT - 200,
			'cliurl' => 'https://cloud.example.org',
		];
	}

	/** @return list<array{stufe: string, text: string}> */
	private function pruefen(): array {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.14.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $vorgabe = '') => match ($key) {
			'installed_version' => $this->s['installiert'],
			'backgroundjobs_mode' => $this->s['modus'],
			default => $vorgabe,
		});
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key) => $key === 'lastcron' ? $this->s['lastcron'] : 0);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $vorgabe = '') => match ($key) {
			'overwrite.cli.url' => $this->s['cliurl'],
			'default_timezone' => 'Europe/Berlin',
			default => $vorgabe,
		});
		$db = $this->createMock(Tabellen::class);
		$db->method('fehlend')->willReturn([]);
		$job = $this->createMock(IJob::class);
		$job->method('getLastRun')->willReturn($this->s['lastrun']);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn(true);
		$jobs->method('getJobsIterator')->willReturnCallback(fn () => [$job]);
		$einbettung = $this->createMock(EinbettungService::class);
		$einbettung->method('origins')->willReturn($this->origins);
		$ordnerMapper = $this->createMock(OrdnerMapper::class);
		$ordnerMapper->method('findAktive')->willReturn($this->ordner);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::JETZT);

		return (new Selbstpruefung($apps, $appConfig, $config, $db, $jobs, $einbettung, $ordnerMapper, $time))->pruefen();
	}

	/** @param list<array{stufe: string, text: string}> $zeilen */
	private function fehler(array $zeilen): array {
		return array_values(array_map(fn ($z) => $z['text'], array_filter($zeilen, fn ($z) => $z['stufe'] === Selbstpruefung::FEHLER)));
	}

	/** @param list<array{stufe: string, text: string}> $zeilen */
	private function alles(array $zeilen): string {
		return implode("\n", array_map(fn ($z) => $z['text'], $zeilen));
	}

	public function testRichtigEingerichtetOhneFehler(): void {
		$zeilen = $this->pruefen();
		$this->assertSame([], $this->fehler($zeilen));
		$this->assertStringContainsString('Client page cannot be embedded (default)', $this->alles($zeilen));
	}

	public function testEinbettungErlaubt(): void {
		$this->origins = ['https://portal.example.org'];
		$this->assertStringContainsString('Client page may be embedded by https://portal.example.org', $this->alles($this->pruefen()));
	}

	public function testAjaxStattCronIstFehler(): void {
		$this->s['modus'] = 'ajax';
		$fehler = $this->fehler($this->pruefen());
		$this->assertCount(1, $fehler);
		$this->assertStringContainsString('occ background:cron', $fehler[0]);
	}

	public function testCronStehtStill(): void {
		$this->s['lastcron'] = self::JETZT - 3600;
		$this->assertCount(1, $this->fehler($this->pruefen()));
	}

	public function testVersionsstandUngleich(): void {
		$this->s['installiert'] = '0.13.1';
		$fehler = $this->fehler($this->pruefen());
		$this->assertCount(1, $fehler);
		$this->assertStringContainsString('occ upgrade', $fehler[0]);
	}

	public function testLokaleCliAdresseIstFehler(): void {
		$this->s['cliurl'] = 'http://localhost';
		$this->assertCount(1, $this->fehler($this->pruefen()));
	}

	public function testProjektordnerMitProblem(): void {
		$o = new Ordner();
		$o->setKontoUid('beispiel');
		$o->setPfad('01 Imagefilm');
		$o->setFehler('ProRes kann der Browser nicht abspielen');
		$this->ordner = [$o, new Ordner()];
		$text = $this->alles($this->pruefen());
		$this->assertStringContainsString('2 project folder(s) enabled, 1 reports a problem', $text);
		$this->assertStringContainsString('beispiel/01 Imagefilm: ProRes', $text);
	}

	public function testOrdnerfehlerAusJsonWirdKlartext(): void {
		$json = json_encode(['m' => 'The browser cannot play %s', 'w' => ['ProRes'], 'd' => 'clip.mov']);
		$this->assertSame('clip.mov: The browser cannot play ProRes', Selbstpruefung::fehlerKlartext((string)$json));
		$this->assertSame('alter Klartext', Selbstpruefung::fehlerKlartext('alter Klartext'));
	}

	public function testZeitInZeitzoneDerInstanz(): void {
		$this->assertStringContainsString('(Europe/Berlin)', $this->alles($this->pruefen()));
	}
}
