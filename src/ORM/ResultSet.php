<?php
/**
 * PHP Version 5.4
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 3.0.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace Cake\ORM;

use Cake\Collection\CollectionTrait;
use Cake\Database\Exception;
use Cake\Database\Type;
use \Countable;
use \Iterator;
use \JsonSerializable;
use \Serializable;

/**
 * Represents the results obtained after executing a query for an specific table
 * This object is responsible for correctly nesting result keys reported from
 * the query, casting each field to the correct type and executing the extra
 * queries required for eager loading external associations.
 *
 */
class ResultSet implements Countable, Iterator, Serializable, JsonSerializable {

	use CollectionTrait;

/**
 * Original query from where results where generated
 *
 * @var Query
 */
	protected $_query;

/**
 * Database statement holding the results
 *
 * @var \Cake\Database\StatementInterface
 */
	protected $_statement;

/**
 * Points to the next record number that should be fetched
 *
 * @var integer
 */
	protected $_index = 0;

/**
 * Last record fetched from the statement
 *
 * @var array
 */
	protected $_current;

/**
 * Default table instance
 *
 * @var \Cake\ORM\Table
 */
	protected $_defaultTable;

/**
 * List of associations that should be eager loaded
 *
 * @var array
 */
	protected $_associationMap = [];

/**
 * Map of fields that are fetched from the statement with
 * their type and the table they belong to
 *
 * @var string
 */
	protected $_map;

/**
 * Results that have been fetched or hydrated into the results.
 *
 * @var array
 */
	protected $_results = [];

/**
 * Whether to hydrate results into objects or not
 *
 * @var boolean
 */
	protected $_hydrate = true;

/**
 * The fully namespaced name of the class to use for hydrating results
 *
 * @var string
 */
	protected $_entityClass;

/**
 * Whether or not to buffer results fetched from the statement
 *
 * @var boolean
 */
	protected $_useBuffering = true;

/**
 * Constructor
 *
 * @param Query from where results come
 * @param \Cake\Database\StatementInterface $statement
 * @return void
 */
	public function __construct($query, $statement) {
		$this->_query = $query;
		$this->_statement = $statement;
		$this->_defaultTable = $this->_query->repository();
		$this->_calculateAssociationMap();
		$this->_hydrate = $this->_query->hydrate();
		$this->_entityClass = $query->repository()->entityClass();
		$this->_useBuffering = $query->bufferResults();
	}

/**
 * Returns the current record in the result iterator
 *
 * @return array|object
 */
	public function current() {
		return $this->_current;
	}

/**
 * Returns the key of the current record in the iterator
 *
 * @return integer
 */
	public function key() {
		return $this->_index;
	}

/**
 * Advances the iterator pointer to the next record
 *
 * @return void
 */
	public function next() {
		$this->_index++;
	}

/**
 * Rewind a ResultSet.
 *
 * @throws Cake\Database\Exception
 * @return void
 */
	public function rewind() {
		if ($this->_index == 0) {
			return;
		}

		if (!$this->_useBuffering) {
			$msg = 'You cannot rewind an un-buffered ResultSet. Use Query::bufferResults() to get a buffered ResultSet.';
			throw new Exception($msg);
		}

		$this->_index = 0;
	}

/**
 * Whether there are more results to be fetched from the iterator
 *
 * @return boolean
 */
	public function valid() {
		if (isset($this->_results[$this->_index])) {
			$this->_current = $this->_results[$this->_index];
			return true;
		}

		$this->_current = $this->_fetchResult();
		$valid = $this->_current !== false;

		if (!$valid && $this->_statement) {
			$this->_statement->closeCursor();
		}

		if ($valid) {
			$this->_bufferResult($this->_current);
		}

		return $valid;
	}

/**
 * Serialize a resultset.
 *
 * Part of Serializable interface.
 *
 * @return string Serialized object
 */
	public function serialize() {
		while ($this->valid()) {
			$this->next();
		}
		return serialize($this->_results);
	}

/**
 * Unserialize a resultset.
 *
 * Part of Serializable interface.
 *
 * @param string Serialized object
 * @return ResultSet The hydrated result set.
 */
	public function unserialize($serialized) {
		$this->_results = unserialize($serialized);
	}

/**
 * Returns the first result in this set and blocks the set so that no other
 * results can be fetched.
 *
 * When using serialized results, the index will be incremented past the
 * end of the results simulating the behavior when the result set is backed
 * by a statement.
 *
 * @return array|object
 */
	public function first() {
		if (isset($this->_results[0])) {
			return $this->_results[0];
		}

		if ($this->valid()) {
			if ($this->_statement) {
				$this->_statement->closeCursor();
			}
			if (!$this->_statement && $this->_results) {
				$this->_index = count($this->_results);
			}
			return $this->_current;
		}
		return null;
	}

/**
 * Gives the number of rows in the result set.
 *
 * Part of the countable interface.
 *
 * @return integer
 */
	public function count() {
		if ($this->_statement) {
			return $this->_statement->rowCount();
		}
		return count($this->_results);
	}

/**
 * Calculates the list of associations that should get eager loaded
 * when fetching each record
 *
 * @return void
 */
	protected function _calculateAssociationMap() {
		$contain = $this->_query->normalizedContainments();

		if (!$contain) {
			return;
		}

		$map = [];
		$visitor = function($level) use (&$visitor, &$map) {
			foreach ($level as $assoc => $meta) {
				$map[$assoc] = [
					'instance' => $meta['instance'],
					'canBeJoined' => $meta['canBeJoined'],
					'entityClass' => $meta['instance']->target()->entityClass()
				];
				if (!empty($meta['associations'])) {
					$visitor($meta['associations']);
				}
			}
		};
		$visitor($contain, []);
		$this->_associationMap = $map;
	}

/**
 * Helper function to fetch the next result from the statement or
 * seeded results.
 *
 * @return mixed
 */
	protected function _fetchResult() {
		if (!$this->_statement) {
			return false;
		}

		$row = $this->_statement->fetch('assoc');
		if ($row === false) {
			return $row;
		}
		return $this->_groupResult($row);
	}

/**
 * Correctly nest results keys including those coming from associations
 *
 * @return array
 */
	protected function _groupResult($row) {
		$defaultAlias = $this->_defaultTable->alias();
		$results = [];
		foreach ($row as $key => $value) {
			$table = $defaultAlias;
			$field = $key;

			if (empty($this->_map[$key])) {
				$parts = explode('__', $key);
				if (count($parts) > 1) {
					$this->_map[$key] = $parts;
				}
			}

			if (!empty($this->_map[$key])) {
				list($table, $field) = $this->_map[$key];
			}

			$results[$table][$field] = $value;
		}

		$results[$defaultAlias] = $this->_castValues(
			$this->_defaultTable,
			$results[$defaultAlias]
		);

		$options = [
			'useSetters' => false,
			'markClean' => true,
			'markNew' => false,
			'guard' => false
		];
		foreach (array_reverse($this->_associationMap) as $alias => $assoc) {
			if (!isset($results[$alias])) {
				continue;
			}
			$instance = $assoc['instance'];
			$results[$alias] = $this->_castValues($instance->target(), $results[$alias]);

			if ($this->_hydrate && $assoc['canBeJoined']) {
				$entity = new $assoc['entityClass']($results[$alias], $options);
				$entity->clean();
				$results[$alias] = $entity;
			}
			$results = $instance->transformRow($results);
		}

		$results = $results[$defaultAlias];
		if ($this->_hydrate && !($results instanceof Entity)) {
			$results = new $this->_entityClass($results, $options);
		}

		return $results;
	}

/**
 * Casts all values from a row brought from a table to the correct
 * PHP type.
 *
 * @param Table $table
 * @param array $values
 * @return array
 */
	protected function _castValues($table, $values) {
		$alias = $table->alias();
		$driver = $this->_query->connection()->driver();
		if (empty($this->types[$alias])) {
			$schema = $table->schema();
			foreach ($schema->columns() as $col) {
				$this->types[$alias][$col] = Type::build($schema->columnType($col));
			}
		}

		foreach ($values as $field => $value) {
			if (!isset($this->types[$alias][$field])) {
				continue;
			}
			$values[$field] = $this->types[$alias][$field]->toPHP($value, $driver);
		}

		return $values;
	}

/**
 * Conditionally buffer the passed result
 *
 * @param array $result the result fetch from the database
 * @return void
 */
	protected function _bufferResult($result) {
		if ($this->_useBuffering) {
			$this->_results[] = $result;
		}
	}

}
