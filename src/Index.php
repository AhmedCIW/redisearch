<?php

namespace FKRediSearch;

use FKRediSearch\Fields\AbstractField;
use FKRediSearch\Fields\FieldInterface;
use FKRediSearch\Fields\GeoField;
use FKRediSearch\Fields\NumericField;
use FKRediSearch\Fields\TextField;
use FKRediSearch\Fields\TagField;
use FKRediSearch\Document\Document;

class Index {

	/**
	 * @var object
	 */
	public $client;

	/**
	 * @var object
	 */
	private $index;

	/**
	 * @var string
	 */
	private $indexName;

	/**
	 * @var bool
	 */
	private $noOffsetsEnabled = FALSE;

	/**
	 * @var bool
	 */
	private $noFieldsEnabled = FALSE;

	/**
	 * @var array|int
	 */
	private $stopWords = NULL;

	public function __construct( $client ) {
		$this->client = $client;
	}

	/**
	 * Drop existing index.
	 *
	 * @param
	 *
	 * @return
	 */
	public function drop() {
		return $this->client->rawCommand( 'FT.DROP', array( $this->getIndexName() ) );
	}

	/**
	 * Create index in redis server from raw params.
	 *
	 * @since    0.1.0
	 *
	 * @param
	 *
	 * @return
	 */
	public function rawCreate( $indexName = NULL, $schema = array(), $stop_words = NULL ) {
		if ( ! isset( $indexName ) ) {
			return;
		}

		if ( empty( $schema ) ) {
			return;
		}

		$index_schema = array( $indexName );

		if ( $stop_words == 0 ) {
			$index_schema = array_merge( $index_schema, array( 'STOPWORDS', 0 ) );
		} else if ( is_array( $stop_words ) && ! empty( $stop_words ) ) {
			$stop_words       = array_map( 'trim', $stop_words );
			$stop_words_count = count( $stop_words );
			if ( $stop_words_count != 0 ) {
				$index_schema = array_merge( $index_schema, array( 'STOPWORDS', $stop_words_count ), $stop_words );
			}
		}

		$index_schema = array_merge( $index_schema, array( 'SCHEMA' ), $schema );

		return $this->client->rawCommand( 'FT.CREATE', $index_schema );
	}

	/**
	 * Create index with passed fields and settings
	 *
	 * @return mixed
	 */
	public function create() {
		$properties = array( $this->getIndexName() );

		if ( $this->isNoOffsetsEnabled() ) {
			$properties[] = 'NOOFFSETS';
		}

		if ( $this->isNoFieldsEnabled() ) {
			$properties[] = 'NOFIELDS';
		}

		if ( ! is_null( $this->stopWords ) ) {
			$properties[] = 'STOPWORDS';
			$properties[] = count( $this->stopWords );
			$properties   = array_merge( $properties, $this->stopWords );
		}

		if ( $this->stopWords == 0 ) {
			$properties[] = 'STOPWORDS';
			$properties[] = 0;
		}

		$properties[] = 'SCHEMA';

		$fieldDefinitions = [];
		foreach ( get_object_vars( $this ) as $field ) {
			if ( $field instanceof FieldInterface ) {
				$fieldDefinitions = array_merge( $fieldDefinitions, $field->getDefinition() );
			}
		}

		if ( count( $fieldDefinitions ) === 0 ) {
			return $this;
		}

		return $this->client->rawCommand( 'FT.CREATE', array_merge( $properties, $fieldDefinitions ) );
	}


	/**
	 * @return bool
	 */
	public function isNoOffsetsEnabled() {
		return $this->noOffsetsEnabled;
	}

	/**
	 * @param bool $noOffsetsEnabled
	 *
	 * @return IndexInterface
	 */
	public function setNoOffsetsEnabled( $noOffsetsEnabled ) {
		$this->noOffsetsEnabled = $noOffsetsEnabled;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isNoFieldsEnabled() {
		return $this->noFieldsEnabled;
	}

	/**
	 * @param bool $noFieldsEnabled
	 *
	 * @return IndexInterface
	 */
	public function setNoFieldsEnabled( $noFieldsEnabled ) {
		$this->noFieldsEnabled = $noFieldsEnabled;

		return $this;
	}


	/**
	 * @param string $indexName
	 *
	 * @return string
	 */
	public function setIndexName( $indexName ) {
		$this->indexName = $indexName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getIndexName() {
		return ! is_string( $this->indexName ) || $this->indexName === '' ? self::class : $this->indexName;
	}

	/**
	 * @param array $stopWords Array of custom stop words
	 *
	 * @return array
	 */
	public function setStopWords( $stopWords = NULL ) {
		$this->stopWords = $stopWords;

		return $this;
	}

	/**
	 * @return int
	 */
	public function noStopWords() {
		$this->stopWords = 0;

		return $this;
	}

	/**
	 * @return int
	 */
	public function synonymAdd( $synonymList = array() ) {
		if ( empty( $synonymList ) || ! is_array( $synonymList ) ) {
			return;
		}

		foreach ( $synonymList as $synonym ) {
			$synonymGroup   = array_map( 'trim', $synonym );
			$synonymCommand = array_merge( array( $this->getIndexName() ), $synonymGroup );

			$this->client->rawCommand( 'FT.SYNADD', $synonymCommand );
		}
	}

	/**
	 * @return array
	 */
	protected function getFields() {
		$fields = [];
		foreach ( get_object_vars( $this ) as $field ) {
			if ( $field instanceof FieldInterface ) {
				$fields[ $field->getName() ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Add documents to the index.
	 *
	 * @since    0.1.0
	 *
	 * @param
	 *
	 * @return object $this
	 */
	public function add( $document ) {
		$properties = $document->getDefinition();
		array_unshift( $properties, $this->getIndexName() );

		return $this->client->rawCommand( 'FT.ADD', $properties );
	}

	/**
	 * @param string $name
	 * @param float  $weight
	 * @param bool   $sortable
	 * @param bool   $noindex
	 *
	 * @return IndexInterface
	 */
	public function addTextField( $name, $weight = 1.0, $sortable = FALSE, $noindex = FALSE ) {
		$this->$name = ( new TextField( $name ) )->setSortable( $sortable )->setNoindex( $noindex )->setWeight( $weight );

		return $this;
	}

	/**
	 * @param string $name
	 * @param float  $weight
	 * @param bool   $sortable
	 * @param bool   $noindex
	 *
	 * @return IndexInterface
	 */
	public function addTagField( $name, $sortable = FALSE, $noindex = FALSE, $separator = ',' ) {
		$this->$name = ( new TagField( $name ) )->setSortable( $sortable )->setNoindex( $noindex )->setSeparator( $separator );

		return $this;
	}

	/**
	 * @param string $name
	 * @param bool   $sortable
	 * @param bool   $noindex
	 *
	 * @return IndexInterface
	 */
	public function addNumericField( $name, $sortable = FALSE, $noindex = FALSE ) {
		$this->$name = ( new NumericField( $name ) )->setSortable( $sortable )->setNoindex( $noindex );

		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return IndexInterface
	 */
	public function addGeoField( $name, $noindex = FALSE ) {
		$this->$name = ( new GeoField( $name ) )->setNoindex( $noindex );

		return $this;
	}

	/**
	 * Delete post from index.
	 *
	 * @since    0.1.0
	 *
	 * @param
	 *
	 * @return object $this
	 */
	public function delete( $id = NULL ) {
		if ( $id === NULL ) {
			return;
		}

		$command = array( $this->indexName, $id, 'DD' );
		$this->client->rawCommand( 'FT.DEL', $command );

		return $this;
	}

	/**
	 * Write entire redisearch index to the disk to persist it.
	 *
	 * @since    0.1.0
	 *
	 * @param
	 *
	 * @return
	 */
	public function writeToDisk() {
		return $this->client->rawCommand( 'SAVE', [] );
	}

	/**
	 * Returns information and statistics on the index.
	 *
	 * @param null $indexName
	 *
	 * @return mixed
	 */
	public function getInfo( $indexName = NULL ) {
		return $this->client->rawCommand( 'FT.INFO', [ empty( $indexName ) ? $this->indexName : $indexName ] );
	}

}
