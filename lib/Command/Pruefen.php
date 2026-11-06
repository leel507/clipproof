<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Command;

use OCA\Clipproof\Service\Selbstpruefung;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Selbsttest nach dem Einsatz, nur lesend:
 *
 *   occ clipproof:check
 *
 * Rückgabe 0, wenn kein Fehler gefunden wurde, sonst 1. Ausgabe englisch.
 */
class Pruefen extends Command {
	private const MARKE = [
		Selbstpruefung::OK => '<info>ok     </info>',
		Selbstpruefung::HINWEIS => '<comment>note   </comment>',
		Selbstpruefung::FEHLER => '<error>ERROR  </error>',
	];

	public function __construct(
		private Selbstpruefung $pruefung,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('clipproof:check')
			->setDescription('Check whether Clipproof is set up correctly (read only)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$zeilen = $this->pruefung->pruefen();
		$zahl = [Selbstpruefung::OK => 0, Selbstpruefung::HINWEIS => 0, Selbstpruefung::FEHLER => 0];
		foreach ($zeilen as $z) {
			$zahl[$z['stufe']]++;
			$output->writeln(self::MARKE[$z['stufe']] . '  ' . $z['text']);
		}
		$output->writeln('');
		if ($zahl[Selbstpruefung::FEHLER] > 0) {
			$output->writeln("<error>{$zahl[Selbstpruefung::FEHLER]} error(s).</error> Clipproof will not fully work like this. Each line says how to fix it.");
			return 1;
		}
		$output->writeln($zahl[Selbstpruefung::HINWEIS] > 0
			? "No errors, {$zahl[Selbstpruefung::HINWEIS]} note(s) worth a look."
			: 'Everything is fine.');
		return 0;
	}
}
