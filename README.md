# RediSearch client

**This is a light weight, CMS/Framework agnostic client for RediSearch**

### How to use
First, you will need to install and configure [Redis](https://redis.io/topics/quickstart) and [RediSearch](https://oss.redislabs.com/redisearch/Quick_Start/).

Then add this package to your project via `composer require` or just downloading this repository.

For all kind of operations, first step is to connect to redis server:

```php
$client = \FKRediSearch\RediSearch\Setup::connect( $server, $port, $password, 0 );
```

Default values are: `$server = '127.0.0.1'`, `$port = 6379`, `$password = null`, `$database = 0`


#### Field types
Redisearch supports some field types:

1. **TEXT** [NOSTEM] [WEIGHT {weight}] [PHONETIC {matcher}] ex: `TEXT NOSTEM WEIGHT 5.6 PHONETIC {maycher}*`
2. **NUMERIC** [SORTABLE] [NOINDEX]
3. **GEO** [SORTABLE] [NOINDEX]
4. **TAG** [SEPARATOR {sep}]

*matcher: Declaring a text field as PHONETIC will perform phonetic matching on it in searches by default. The obligatory {matcher} argument specifies the phonetic algorithm and language used. The following matchers are supported:

`dm:en` - Double Metaphone for English
`dm:fr` - Double Metaphone for French
`dm:pt` - Double Metaphone for Portuguese
`dm:es` - Double Metaphone for Spanish

#### Create index
To create index, first we need to specify `indexName`, then the we can pass some optional flags and at the end, schema (fields). Available commands are:

```php
$index = new Index( $client ); // Initiate Index
$index->setIndexName('test22'); // Set indexName
```

Redisearch by defaut support basic english stop-words. There is two option, you can totally disable excluding stop-word or specify your own list of them:
```php
$index->setStopWords( 0 ); // This will disables excluding of stop-words
// Or you can add your list of stop-words
$stop_words = array('this', 'that', 'it', 'what', 'is', 'are');
$index->setStopWords( $stop_words );
```

Some other flags are:
```php
$index->setNoOffsetsEnabled( true ); // If set, we do not store term offsets for documents (saves memory, does not allow exact searches or highlighting).
$index->setNoFieldsEnabled( true ) // If set, we do not store field bits for each term. Saves memory, does not allow filtering by specific fields.
```

And finally, we need to specify the schema (the fields, their types and flags):
```php
$index->addTextField( $name, $weight = 1.0, $sortable = false, $noindex = false)
      ->addTagField( $name, $sortable = false, $noindex = false, $separator = ',')
      ->addNumericField( $name, $sortable = false, $noindex = false )
      ->addGeoField( $name, $noindex = false );
      
// Example 
$index->addTextField('title', 0.5, true, true) // `title TEXT WEIGHT 0.5, SORTABLE NOINDEX`
    ->addTextField('content') // `content TEXT WEIGHT 1.0`
    ->addTagField('category', true, true, ';') // `category TAG SEPARATOR ',' SORTABLE NOINDEX`
    ->addGeoField('location') // `location GEO`
    ->create(); // Finally, create the index.
```

**Some notes**:
* field weight is float value and only applies to the field. Default value is 1.0
* Don't forget to call `create()` method at the end

#### Synonym
Synonym matching is supported as well:
```php
$synonym = array(
  array( 'boy', 'child', 'baby' ),
  array( 'girl', 'child', 'baby' ),
  array( 'man', 'person', 'adult' )
);

$index->synonymAdd( $synonym );
````

#### Add document to the index (indexing)
```php
$document = new Document();
$document->setScore(0.3); // The document's rank based on the user's ranking. This must be between 0.0 and 1.0. Default value is 1.0
$document->setLanguage('english'); // This is usefull for stemming
$document->setNoSave( true ); //  If set to true, we will not save the actual document in the database and only index it.
$document->setPayload( true ); // Optionally set a binary safe payload string to the document, that can be evaluated at query time by a custom scoring function, or retrieved to the client.
// And the fields 
$document->setFields(
  array(
    'title'       => 'Document title like post title',
    'category'    => 'search, fuzzy, synonym, phonetic',
    'date'        => strtotime( '2019-01-14 01:12:00' ),
    'location'    => new GeoLocation(-77.0366, 38.8977)),
  )
);

$index->add( $document ); // Add document to the index (in other term, index the document)
```

#### Persistence
After indexing documents, index can be written to the disk and in case of network issues, you won't miss your index.

```php
$index->writeToDisk();
```

#### Search
And here is how to search:
```php
$search = new Query( $client, 'indexName' );
$results = $search
        ->sortBy( $fieldName, $order = 'ASC' )
        ->geoFilter( $fieldName, $longitude, $latitude, $radius, $distanceUnit = 'km' )
        ->numericFilter( $fieldName, $min, $max = null )
        ->withScores() // If set, we also return the relative internal score of each document. this can be used to merge results from multiple instances
        ->verbatim() // if set, we do not try to use stemming for query expansion but search the query terms verbatim.
        ->withPayloads() // If set, we retrieve optional document payloads (see FT.ADD). the payloads follow the document id, and if WITHSCORES was set, follow the scores
        ->noStopWords() //  If set, we do not filter stopwords from the query
        ->slop() // If set, we allow a maximum of N intervening number of unmatched offsets between phrase terms. (i.e the slop for exact phrases is 0)
        ->inKeys( $number, $keys ) // If set, we limit the result to a given set of keys specified in the list. the first argument must be the length of the list, and greater than zero. Non-existent keys are ignored - unless all the keys are non-existent.
        ->inFields( $number, $fields ) // If set, filter the results to ones appearing only in specific fields of the document, like title or URL. num is the number of specified field arguments
        ->limit( $offset, $pageSize = 10 ) // If set, we limit the results to the offset and number of results given. The default is 0 10
        ->highlight( $fields, $openTag = '<strong>', $closeTag = '</strong>') 
        ->summarize( $fields = array(), $fragmentCount = 3, $fragmentLength = 50, $separator = '...') // Use this option to return only the sections of the field which contain the matched text
        ->return( $fields ) // Use this keyword to limit which fields from the document are returned. num is the number of fields following the keyword. If num is 0, it acts like NOCONTENT.
        ->noContent() // If it appears after the query, we only return the document ids and not the content. This is useful if RediSearch is only an index on an external document collection
        ->search( $query, $documentsAsArray = false ); // By default, return values will be object, but if TRUE is passed as `$documentsAsArray` results will return as array
```

There is two methods available to apply on search results which are:

```php
$results->getCount(); // Returns search results numbers
$results->getDocuments(); // Returns search results object or array
```

**Notes**:
* NUMBERIC, TAG and GEO fields only can be used as filter and matching not working on them. 

**Todos**:
* Add support for suggestion (auto-complete).
* Add support for TAGVALS (This will return all existing TAGs indexed within a specific field. It is extremely usefull for facet search fileters).
* Add comment to all methods.
* Add support for Spell Check (This is useful to show *Do you mean: ...* feature like google and other search engines does).