<?php
/**
 * @copyright Copyright (c) 2021 René Gieling <github@dartcafe.de>
 *
 * @author René Gieling <github@dartcafe.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Polls\Command\Db;

use Doctrine\DBAL\Schema\Schema;
use OCA\Polls\Db\TableManager;
use OCA\Polls\Db\IndexManager;
use OCA\Polls\Command\Command;
use OCP\IDBConnection;

class Rebuild extends Command {
	protected string $name = parent::NAME_PREFIX . 'db:rebuild';
	protected string $description = 'Rebuilds poll\'s table structure';
	protected array $operationHints = [
		'All polls tables will get checked against the current schema.',
		'NO data migration will be executed, so make sure you have a backup of your database.',
	];

	public function __construct(
		private TableManager $tableManager,
		private IndexManager $indexManager,
		private IDBConnection $connection,
		private Schema $schema,
	) {
		parent::__construct();
	}

	protected function runCommands(): int {
		$this->schema = $this->connection->createSchema();
		$this->indexManager->setSchema($this->schema);
		$this->tableManager->setSchema($this->schema);

		$this->printComment('Step 1. Remove all indices and foreign key constraints');
		$this->deleteForeignKeyConstraints();
		$this->deleteGenericIndices();
		$this->deleteUniqueIndices();

		$this->printComment('Step 2. Remove all orphaned tables and columns');
		$this->removeObsoleteTables();
		$this->removeObsoleteColumns();

		$this->connection->migrateToSchema($this->schema);
		
		$this->printComment('Step 3. Create or update tables to current shema');
		$this->createOrUpdateSchema();
		
		$this->connection->migrateToSchema($this->schema);

		$this->printComment('Step 4. set hashes for votes and options');
		$this->migrateOptionsToHash();
		
		$this->printComment('Step 5. Remove invalid records (orphaned and duplicates)');
		$this->cleanTables();
		
		$this->printComment('Step 6. Recreate indices and foreign key constraints');
		$this->addForeignKeyConstraints();
		$this->addIndices();

		$this->connection->migrateToSchema($this->schema);
		
		return 0;
	}

	/**
	 * add an on delete fk contraint to all tables referencing the main polls table
	 */
	private function addForeignKeyConstraints(): void {
		$this->printComment(' - Add foreign key constraints');
		$messages = $this->indexManager->createForeignKeyConstraints();
		$this->printInfo($messages, '   ');
	}

	/**
	 * Create index for $table
	 */
	private function addIndices(): void {
		$this->printComment(' - Add indices');
		$messages = $this->indexManager->createIndices();
		$this->printInfo($messages, '   ');
	}

	/**
	 * Iterate over tables and make sure, the are created or updated
	 * according to the schema
	 */
	private function createOrUpdateSchema(): void {
		$this->printComment(' - Set db structure');
		$messages = $this->tableManager->createTables();
		$this->printInfo($messages, '   ');
	}

	/**
	 * Add or update hash for votes and options
	 */
	private function migrateOptionsToHash(): void {
		$this->printComment(' - Add or update hashes');
		$messages = $this->tableManager->migrateOptionsToHash();
		$this->printInfo($messages, '   ');
	}

	private function removeObsoleteColumns(): void {
		$this->printComment(' - Drop orphaned columns');
		$messages = $this->tableManager->removeObsoleteColumns();
		$this->printInfo($messages, '   ');
	}

	/**
	 * Remove obsolete tables if they still exist
	 */
	private function removeObsoleteTables(): void {
		$this->printComment(' - Drop orphaned tables');
		$messages = $this->tableManager->removeObsoleteTables();
		$this->printInfo($messages, '   ');
	}

	/**
	 * Initialize last poll interactions timestamps
	 */
	public function resetLastInteraction(): void {
		$messages = $this->tableManager->resetLastInteraction();
		$this->printInfo($messages, '   ');
	}

	/**
	 * Remove obsolete tables if they still exist
	 */
	private function cleanTables(): void {
		$this->printComment(' - Remove orphaned records');
		$this->tableManager->removeOrphaned();

		$this->printComment(' - Remove duplicates');
		$messages = $this->tableManager->deleteAllDuplicates();
		$this->printInfo($messages, '   ');
	}

	private function deleteForeignKeyConstraints(): void {
		$this->printComment(' - Remove foreign key constraints');
		$messages = $this->indexManager->removeAllForeignKeyConstraints();
		$this->printInfo($messages, '   ');
	}

	/**
	 * add an on delete fk contraint to all tables referencing the main polls table
	 */
	private function deleteGenericIndices(): void {
		$this->printComment(' - Remove generic indices');
		$messages = $this->indexManager->removeAllGenericIndices();
		$this->printInfo($messages, '   ');
	}

	/**
	 * add an on delete fk contraint to all tables referencing the main polls table
	 */
	private function deleteUniqueIndices(): void {
		$this->printComment(' - Remove unique indices');
		$messages = $this->indexManager->removeAllUniqueIndices();
		$this->printInfo($messages, '   ');
	}
}
