<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Command;

use OCA\Clipproof\Db\ReviewMapper;
use OCA\Clipproof\Exception\ReviewException;
use OCA\Clipproof\Service\ReviewService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Neuen Schnitt an ein Review hängen; der Kundenlink bleibt gleich.
 *
 *   occ clipproof:add-version <user> <review-id> <path>
 */
class Version extends Command {
	public function __construct(
		private ReviewMapper $reviews,
		private ReviewService $reviewService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('clipproof:add-version')
			->setDescription('Attach a new cut to a review (the client link stays the same)')
			->addArgument('user', InputArgument::REQUIRED, 'Owner of the review')
			->addArgument('review', InputArgument::REQUIRED, 'Id of the review')
			->addArgument('path', InputArgument::REQUIRED, 'Path of the file in the user\'s files');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		try {
			$review = $this->reviews->findForOwner((int)$input->getArgument('review'), $uid);
			$version = $this->reviewService->versionAnhaengen($review, $uid, $this->reviewService->dateiIdZuPfad($uid, (string)$input->getArgument('path')));
		} catch (DoesNotExistException) {
			$output->writeln('<error>Review not found.</error>');
			return 1;
		} catch (ReviewException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Version ' . $version->getNumber() . ' attached.');
		return 0;
	}
}
