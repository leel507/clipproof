<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCA\Clipproof\Service\EinbettungService;
use OCA\Clipproof\Service\Einstellungen;
use OCA\Clipproof\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Die Kundenseite. Öffentlich, Zugang nur über das Review-Token, ohne
 * Cookies und ohne Nextcloud-Konto. Einzige Seite mit eigener CSP für
 * die Einbettung in eine eingestellte Seite.
 */
class PublicPageController extends Controller {
	public function __construct(
		IRequest $request,
		private TokenService $tokens,
		private EinbettungService $einbettung,
		private IInitialState $initialState,
		private LoggerInterface $logger,
		private IUserManager $users,
		private IAppConfig $appConfig,
		private Einstellungen $einstellungen,
		private IFactory $l10nFactory,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[FrontpageRoute(verb: 'GET', url: '/r/{token}')]
	public function show(string $token): TemplateResponse {
		try {
			$review = $this->tokens->resolve($token);
		} catch (TokenInvalidException $e) {
			// Grund nur ins Protokoll, nie das Token selbst.
			$this->logger->info('Kundenseite: ungültiger Link ({grund})', ['grund' => $e->getReason(), 'app' => Application::APP_ID]);
			$antwort = new TemplateResponse(Application::APP_ID, 'ungueltig', [], TemplateResponse::RENDER_AS_GUEST);
			$antwort->setStatus(Http::STATUS_NOT_FOUND);
			$antwort->addHeader('Referrer-Policy', 'no-referrer');
			$antwort->throttle(['grund' => $e->getReason()]);
			return $antwort;
		}

		$besitzer = $this->users->getDisplayName((string)$review->getOwnerUid()) ?? (string)$review->getOwnerUid();
		$this->initialState->provideInitialState('kunde', [
			'token' => $token,
			'info' => [
				'besitzer' => $besitzer,
				'datenschutzUrl' => $this->appConfig->getValueString('theming', 'privacyUrl', ''),
				'archivJahre' => $this->einstellungen->aufbewahrungJahre(),
				'linkTage' => $this->einstellungen->tageNachFreigabe(),
			],
		]);
		Util::addScript(Application::APP_ID, 'clipproof-kunde');
		// Die Anrede steckt in der Übersetzung: `de` duzt, `de_DE` siezt. Die später geladene Datei
		// überschreibt die Vorgabe, die Nextcloud zum Skript lädt.
		if (str_starts_with($this->l10nFactory->findLanguage(Application::APP_ID), 'de')) {
			Util::addTranslations(Application::APP_ID, $review->getAnrede() === 'du' ? 'de' : 'de_DE');
		}

		$antwort = new TemplateResponse(Application::APP_ID, 'kunde', [], TemplateResponse::RENDER_AS_BASE);
		$antwort->setContentSecurityPolicy($this->einbettung->kundenseitenCsp());
		// Das Token steht in der Adresse: nie als Referer an fremde Server.
		$antwort->addHeader('Referrer-Policy', 'no-referrer');
		return $antwort;
	}
}
