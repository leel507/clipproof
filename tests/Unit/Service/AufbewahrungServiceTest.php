<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Tests\Unit\Service;

use OCA\Clipproof\Service\AufbewahrungService;
use PHPUnit\Framework\TestCase;

/** Sechs Jahre ab Ende des Kalenderjahres, deutsche Zeit (§ 257 Abs. 5 HGB). */
class AufbewahrungServiceTest extends TestCase {
	private static function t(string $s): int {
		return (new \DateTimeImmutable($s, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
	}

	public function testMitteDesJahres(): void {
		$bis = AufbewahrungService::aufbewahrenBis(self::t('2026-10-12 14:00'));
		$this->assertSame(self::t('2033-01-01 00:00'), $bis);
		$this->assertSame('2032-12-31', AufbewahrungService::letzterTag($bis));
	}

	/** Je Schreiben: verfallen genau dann, wenn aufbewahrenBis erreicht ist. */
	public function testVerfallGrenzePasstZuAufbewahrenBis(): void {
		foreach (['2026-01-01 00:30', '2026-06-15 12:00', '2026-12-31 23:30'] as $eingang) {
			foreach (['2032-12-31 23:30', '2033-01-01 00:30', '2033-06-01 12:00'] as $jetzt) {
				$e = self::t($eingang);
				$j = self::t($jetzt);
				$this->assertSame(AufbewahrungService::aufbewahrenBis($e) <= $j, $e < AufbewahrungService::verfallGrenze($j), "$eingang am $jetzt");
			}
		}
	}

	/** Löschprotokoll: drei Jahre ab Ende des Kalenderjahres des Eintrags. */
	public function testProtokollGrenze(): void {
		$grenze = AufbewahrungService::protokollGrenze(self::t('2030-01-01 00:30'));
		$this->assertLessThan($grenze, self::t('2026-12-31 23:30'), 'Eintrag aus 2026 ist Anfang 2030 abgelaufen');
		$this->assertGreaterThanOrEqual($grenze, self::t('2027-01-01 00:30'), 'Eintrag aus 2027 bleibt');
		$this->assertGreaterThanOrEqual(AufbewahrungService::protokollGrenze(self::t('2029-12-31 23:30')), self::t('2026-06-01 12:00'), 'Eintrag aus 2026 bleibt bis Ende 2029');
	}

	public function testSilvesterAbendZaehltZumAltenJahr(): void {
		// 31.12. 23:30 deutscher Zeit ist in UTC noch 22:30, aber auch umgekehrt:
		// maßgeblich ist das deutsche Kalenderjahr.
		$this->assertSame('2032-12-31', AufbewahrungService::letzterTag(AufbewahrungService::aufbewahrenBis(self::t('2026-12-31 23:30'))));
	}

	public function testNeujahrNachtZaehltZumNeuenJahr(): void {
		// 1.1. 00:30 deutscher Zeit ist in UTC noch der 31.12.
		$this->assertSame('2033-12-31', AufbewahrungService::letzterTag(AufbewahrungService::aufbewahrenBis(self::t('2027-01-01 00:30'))));
	}
}
