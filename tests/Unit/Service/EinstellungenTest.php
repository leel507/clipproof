<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Service\Einstellungen;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class EinstellungenTest extends TestCase {
	/** @var array<string, mixed> */
	private array $werte = [];

	private function einstellungen(bool $admin = false, array $mitglied = []): Einstellungen {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $k, $v = '') => $this->werte[$k] ?? $v);
		$config->method('getValueInt')->willReturnCallback(fn ($app, $k, $v = 0) => $this->werte[$k] ?? $v);
		$config->method('setValueString')->willReturnCallback(function ($app, $k, $v) {
			$this->werte[$k] = $v;
			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function ($app, $k, $v) {
			$this->werte[$k] = $v;
			return true;
		});
		$gruppen = $this->createMock(IGroupManager::class);
		$gruppen->method('isAdmin')->willReturn($admin);
		$gruppen->method('isInGroup')->willReturnCallback(fn ($uid, $g) => in_array($g, $mitglied, true));
		$gruppen->method('groupExists')->willReturnCallback(fn ($g) => in_array($g, ['schnitt', 'kunden'], true));
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValueString')->willReturn('Europe/Berlin');
		return new Einstellungen($config, $gruppen, $system);
	}

	public function testOhneGruppenDarfJeder(): void {
		$this->assertTrue($this->einstellungen()->darf('jemand'));
		$this->assertFalse($this->einstellungen()->darf(''));
	}

	public function testNurMitgliederUndAdmins(): void {
		$this->einstellungen()->setzeGruppen(['schnitt', 'gibtsnicht', ' ']);
		$this->assertSame(['schnitt'], $this->einstellungen()->gruppen());
		$this->assertFalse($this->einstellungen()->darf('aussen'));
		$this->assertTrue($this->einstellungen(false, ['schnitt'])->darf('innen'));
		$this->assertTrue($this->einstellungen(true)->darf('admin'));
	}

	public function testAufbewahrungVorgabeBisLoeschen(): void {
		$e = $this->einstellungen();
		$this->assertSame(0, $e->aufbewahrungJahre());
		$this->assertSame(3, $e->protokollJahre());
		$e->setzeAufbewahrungJahre(6);
		$this->assertSame(6, $e->aufbewahrungJahre());
		$e->setzeProtokollJahre(0);
		$this->assertSame(1, $e->protokollJahre());
		$this->assertSame('Europe/Berlin', $e->zeitzone());
	}

	public function testTageBegrenzt(): void {
		$e = $this->einstellungen();
		$this->assertSame(30, $e->tageNachFreigabe());
		$e->setzeTageNachFreigabe(0);
		$this->assertSame(1, $e->tageNachFreigabe());
		$e->setzeTageNachFreigabe(99999);
		$this->assertSame(3650, $e->tageNachFreigabe());
		$e->setzeTageNachFreigabe(14);
		$this->assertSame(14 * 86400, $e->gueltigNachFreigabe());
	}

	public function testOrdnerspracheVorgabeEnglisch(): void {
		$e = $this->einstellungen();
		$this->assertSame('en', $e->ordnersprache());
		$this->assertSame('Previous versions', $e->vorversionen());
		$this->assertSame('Assets', $e->assets());
	}

	public function testOrdnerspracheDeutschUndUnbekannteFaelltZurueck(): void {
		$e = $this->einstellungen();
		$e->setzeOrdnersprache('de');
		$this->assertSame('Vorversionen', $e->vorversionen());
		$e->setzeOrdnersprache('fr');
		$this->assertSame('Previous versions', $e->vorversionen());
	}

	public function testVorversionenInBeidenSprachenErkannt(): void {
		$this->assertTrue(Einstellungen::istVorversionen('Previous versions'));
		$this->assertTrue(Einstellungen::istVorversionen('Vorversionen'));
		$this->assertFalse(Einstellungen::istVorversionen('Assets'));
		$this->assertFalse(Einstellungen::istVorversionen('vorversionen'));
	}
}
