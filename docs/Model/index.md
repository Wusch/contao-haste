# Haste Model

Provides a methods to handle "many to many" relation between tables.

Important notes:

- Please update the database after defining the new relation.
- The relation table name consists of the original table name and related table name unless specified differently, e.g. tl_table_one_table_two.
- If you delete a record in the related table then the relation tables are automatically updated.
- Automatically adds a filter in the back end if you set `'filter' => true,` like for any other field (note that `filter` has to be in your `panelLayout`)
- Automatically adds a search box in the back end if you set `'search' => true,` like for any other field  (note that `search` has to be in your `panelLayout`). It lists all the fields that are searchable in the related table.
- Relations are always bidirectional. If you want to have unidirectional ones, you need to have separate relation tables.

## Examples ##

### Define relation in DCA ###

```php
<?php

$GLOBALS['TL_DCA']['tl_table_one']['fields']['my_field']['relation'] = array
(
    'type' => 'haste-ManyToMany',
    'load' => 'lazy',
    'table' => 'tl_table_two', // the related table,
    'tableSql' => 'DEFAULT CHARSET=big5 COLLATE big5_chinese_ci ENGINE=MyISAM', // related table options (optional)
    'reference' => 'id', // current table field (optional)
    'referenceSql' => "int(10) unsigned NOT NULL default '0'", // current table field sql definition (optional)
    'referenceColumn' => 'my_reference_field', // a custom column name in relation table (optional)
    'field' => 'id', // related table field (optional)
    'fieldSql' => "int(10) unsigned NOT NULL default '0'", // related table field sql definition (optional)
    'fieldColumn' => 'my_related_field', // a custom column name in relation table (optional)
    'relationTable' => '', // custom relation table name (optional)
    'forceSave' => true, // false by default. If set to true it does not only store the values in the relation tables but also the "my_relation" field
    'skipInstall' => true, // false by default. Do not add relation table. Useful if you use Doctrine relations on the same tables.
);
```

### Define model class ###

The model class must extend \Haste\Model\Model.

```php
<?php

class TableOneModel extends Model\Model
{
    // ...
}
```

Then call it as usual

```php
$objRelated = $objModel->getRelated('my_field');
```

You can also fetch the related or reference values manually:

```php
$arrRelated = static::getRelatedValues('tl_table_one', 'my_field', 123);

$arrReference = static::getReferenceValues('tl_table_one', 'my_field', array(1, 2, 3));
```
