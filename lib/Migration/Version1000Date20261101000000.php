<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Erstes Schema von Clipproof.
 *
 * Zeitpunkte als Unix-Sekunden (UTC) in bigint, damit sie sich auf jeder
 * Datenbank gleich verhalten. Bildraten als Bruch, Frames als ganze
 * Zahlen. Ja/Nein-Felder sind nullable, weil Nextcloud NOT NULL bei
 * Booleans ablehnt; die App liest null als false.
 *
 * Indexnamen beginnen mit cp_: In PostgreSQL gelten sie für das ganze
 * Schema, und andere Apps dürfen dieselben Namen benutzen.
 */
class Version1000Date20261101000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('clipproof_reviews')) {
			$t = $schema->createTable('clipproof_reviews');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('token_enc', Types::TEXT, ['notnull' => false]);
			$t->addColumn('token_expires_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('token_revoked_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('comments_locked', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('anrede', Types::STRING, ['notnull' => true, 'length' => 3, 'default' => 'sie']);
			$t->addColumn('freigabe_am', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('archiviert_am', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('archiv_anlass', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('aufbewahren_bis', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('ordner_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('gruppe', Types::STRING, ['notnull' => false, 'length' => 200]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token_hash'], 'cp_rev_token');
			$t->addIndex(['owner_uid'], 'cp_rev_owner');
			$t->addIndex(['aufbewahren_bis'], 'cp_rev_aufbewahren');
			$t->addIndex(['ordner_id'], 'cp_rev_ordner');
		}

		if (!$schema->hasTable('clipproof_versions')) {
			$t = $schema->createTable('clipproof_versions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('review_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('number', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('fps_num', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('fps_den', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('fps_source', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('drop_frame', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('start_tc_frames', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('duration_frames', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('width', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('height', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('file_missing', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['review_id', 'number'], 'cp_ver_nummer');
			$t->addIndex(['file_id'], 'cp_ver_datei');
		}

		if (!$schema->hasTable('clipproof_guests')) {
			$t = $schema->createTable('clipproof_guests');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('review_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 100]);
			$t->addColumn('secret_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('last_seen_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['secret_hash'], 'cp_gast_geheim');
			$t->addIndex(['review_id'], 'cp_gast_review');
		}

		if (!$schema->hasTable('clipproof_comments')) {
			$t = $schema->createTable('clipproof_comments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('author_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('guest_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('author_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('frame_in', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('frame_out', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('text', Types::TEXT, ['notnull' => false]);
			$t->addColumn('drawing', Types::TEXT, ['notnull' => false]);
			$t->addColumn('done', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('submission_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['version_id'], 'cp_kom_version');
			$t->addIndex(['guest_id', 'submission_id'], 'cp_kom_entwurf');
			$t->addIndex(['submission_id'], 'cp_kom_abgabe');
		}

		if (!$schema->hasTable('clipproof_submissions')) {
			$t = $schema->createTable('clipproof_submissions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('review_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('version_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('guest_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('comment_count', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('protocol_text', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['review_id'], 'cp_abg_review');
		}

		if (!$schema->hasTable('clipproof_loeschungen')) {
			$t = $schema->createTable('clipproof_loeschungen');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('review_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('anlass', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('kommentare', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('gaeste', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('abgaben', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('geloescht_am', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['token_hash'], 'cp_loesch_token');
		}

		if (!$schema->hasTable('clipproof_ordner')) {
			$t = $schema->createTable('clipproof_ordner');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			// Wem die Dateien gehören und wem die Reviews daraus.
			$t->addColumn('konto_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('folder_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('pfad', Types::STRING, ['notnull' => true, 'length' => 1000]);
			$t->addColumn('titel', Types::STRING, ['notnull' => true, 'length' => 200]);
			$t->addColumn('aktiv', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('fehler', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('fehler_datei', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('geprueft_am', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['folder_id'], 'cp_ordner_folder');
			$t->addIndex(['owner_uid'], 'cp_ordner_owner');
		}

		return $schema;
	}
}
