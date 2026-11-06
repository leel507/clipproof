<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Command;

use OCA\Clipproof\Exception\ReviewException;
use OCA\Clipproof\Service\ReviewService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Legt ein Review aus einer Videodatei an, ohne Oberfläche (für Skripte
 * und Tests). Gibt den Kundenlink aus; der ist ein Geheimnis.
 *
 *   occ clipproof:create <user> <path> [--title=...]
 */
class Anlegen extends Command {
	public function __construct(
		private ReviewService $reviews,
		private IRootFolder $rootFolder,
		private IURLGenerator $urls,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('clipproof:create')
			->setDescription('Create a review from a video file and print the client link')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud account that owns the review')
			->addArgument('path', InputArgument::REQUIRED, 'Path of the file in this account\'s files')
			->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title of the review');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$benutzer = (string)$input->getArgument('user');
		$pfad = (string)$input->getArgument('path');
		try {
			$datei = $this->rootFolder->getUserFolder($benutzer)->get($pfad);
		} catch (\Throwable) {
			$output->writeln('<error>File not found: ' . $pfad . '</error>');
			return 1;
		}
		if (!$datei instanceof File) {
			$output->writeln('<error>Not a file: ' . $pfad . '</error>');
			return 1;
		}
		$titel = (string)($input->getOption('title') ?? pathinfo($pfad, PATHINFO_FILENAME));
		try {
			[$review, $token] = $this->reviews->anlegen($benutzer, $datei->getId(), $titel);
		} catch (ReviewException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln((string)$review->getId());
		$output->writeln($this->urls->linkToRouteAbsolute('clipproof.publicPage.show', ['token' => $token]));
		return 0;
	}
}
