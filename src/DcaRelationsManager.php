<?php

namespace Codefog\HasteBundle;

use Codefog\HasteBundle\Model\DcaRelationsModel;
use Contao\ArrayUtil;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Config\ResourceFinderInterface;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Database;
use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\String\UnicodeString;

class DcaRelationsManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Formatter $formatter,
        private readonly RequestStack $requestStack,
        private readonly ResourceFinderInterface $resourceFinder,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly UndoManager $undoManager,
    )
    {}

    private array $relationsCache = [];
    private array $filterableFields = [];
    private array $searchableFields = [];

    /**
     * This cache stores the table and record ID that has been already purged.
     * It allows you to have multiple fields with the same relation in one DCA
     * and prevents the earlier field values to be removed by the last one
     * (the helper table is purged only once in this case, for the first field).
     */
    private array $purgeCache = [];

    /**
     * This cache is in fact a hotfix for the "override all" mode. It simply
     * does not allow the last record to be double-saved.
     */
    private array $overrideAllCache = [];

    #[AsHook('loadDataContainer')]
    public function addRelationCallbacks(string $table): void
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            return;
        }

        $blnCallbacks = false;

        foreach ($GLOBALS['TL_DCA'][$table]['fields'] as $fieldName => $fieldConfig) {
            if (($relation = $this->getRelation($table, $fieldName)) === null) {
                continue;
            }

            $blnCallbacks = true;

            // Update the field configuration
            $GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['eval']['doNotSaveEmpty'] = true;
            $GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['load_callback'][] = [static::class, 'getRelatedRecords'];
            $GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['save_callback'][] = [static::class, 'updateRelatedRecords'];

            // Use custom filtering
            if (isset($fieldConfig['filter']) && $fieldConfig['filter']) {
                $GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['filter'] = false;
                $this->filterableFields[$fieldName] = $relation;
            }

            // Use custom search filtering
            if (isset($fieldConfig['search']) && $fieldConfig['search']) {
                $GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['search'] = false;
                $this->searchableFields[$fieldName] = $relation;
            }
        }

        // Add global callbacks
        if ($blnCallbacks) {
            $GLOBALS['TL_DCA'][$table]['config']['ondelete_callback'][] = [static::class, 'deleteRelatedRecords'];
            $GLOBALS['TL_DCA'][$table]['config']['oncopy_callback'][] = [static::class, 'copyRelatedRecords'];
        }

        $GLOBALS['TL_DCA'][$table]['config']['ondelete_callback'][] = [static::class, 'cleanRelatedRecords'];

        // Add filter and search callbacks for the backend only
        if (($request = $this->requestStack->getCurrentRequest()) !== null && $this->scopeMatcher->isBackendRequest($request)) {
            if (count($this->filterableFields) > 0) {
                $GLOBALS['TL_DCA'][$table]['config']['onload_callback'][] = [static::class, 'filterByRelations'];

                if (isset($GLOBALS['TL_DCA'][$table]['list']['sorting']['panelLayout'])){
                    $GLOBALS['TL_DCA'][$table]['list']['sorting']['panelLayout'] = preg_replace('/filter/', 'haste_filter;filter', $GLOBALS['TL_DCA'][$table]['list']['sorting']['panelLayout'], 1);
                    $GLOBALS['TL_DCA'][$table]['list']['sorting']['panel_callback']['haste_filter'] = [static::class, 'addRelationFilters'];
                }
            }

            if (count($this->searchableFields) > 0) {
                $GLOBALS['TL_DCA'][$table]['config']['onload_callback'][] = [static::class, 'filterBySearch'];

                if (isset($GLOBALS['TL_DCA'][$table]['list']['sorting']['panelLayout'])){
                    $GLOBALS['TL_DCA'][$table]['list']['sorting']['panelLayout'] = preg_replace('/search/', 'haste_search;search', $GLOBALS['TL_DCA'][$table]['list']['sorting']['panelLayout'], 1);
                    $GLOBALS['TL_DCA'][$table]['list']['sorting']['panel_callback']['haste_search'] = [static::class, 'addRelationSearch'];
                }
            }
        }
    }

    /**
     * Update the records in related table.
     */
    public function updateRelatedRecords(mixed $value, DataContainer $dc): mixed
    {
        if (($relation = $this->getRelation($dc->table, $dc->field)) === null) {
            return $value;
        }

        $cacheKey = $relation['table'] . $dc->activeRecord->{$relation['reference']};
        $field = $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field] ?? [];

        // Support for csv values
        if (($field['eval']['multiple'] ?? false) && ($field['eval']['csv'] ?? false)) {
            $values = explode($field['eval']['csv'], $value);
        } else {
            $values = StringUtil::deserialize($value, true);
        }

        // Check the purge cache
        if (!in_array($cacheKey, $this->purgeCache, true)) {
            $this->purgeRelatedRecords($relation, $dc->activeRecord->{$relation['reference']});
            $this->purgeCache[] = $cacheKey;
        }

        $saveRecords = true;

        // Do not save the record again in "override all" mode if it has been saved already
        if ('overrideAll' === Input::get('act')) {
            if (in_array($cacheKey, $this->overrideAllCache, true)) {
                $saveRecords = false;
            }

            $this->overrideAllCache[] = $cacheKey;
        }

        // Save the records in a relation table
        if ($saveRecords) {
            foreach ($values as $v) {
                $this->connection->insert($relation['table'], [
                    $relation['reference_field'] => $dc->activeRecord->{$relation['reference']},
                    $relation['related_field'] => $v,
                ]);
            }
        }

        // Force save the value
        if ($relation['forceSave']) {
            return $value;
        }

        return null;
    }

    /**
     * Delete the records in related table.
     */
    public function deleteRelatedRecords(DataContainer $dc, int $undoId): void
    {
        $this->loadDataContainers();
        $undo = [];

        foreach ($GLOBALS['TL_DCA'] as $table => $dca) {
            foreach ($dca['fields'] as $fieldName => $fieldConfig) {
                $relation = $this->getRelation($table, $fieldName);

                if ($relation === null || ($relation['reference_table'] !== $dc->table && $relation['related_table'] !== $dc->table)) {
                    continue;
                }

                // Store the related values for further save in tl_undo table
                if ($relation['reference_table'] === $dc->table) {
                    $undo[] = [
                        'table' => $dc->table,
                        'relationTable' => $table,
                        'relationField' => $fieldName,
                        'reference' => $dc->{$relation['reference']},
                        'values' => DcaRelationsModel::getRelatedValues($table, $fieldName, $dc->{$relation['reference']})
                    ];

                    $this->purgeRelatedRecords($relation, $dc->{$relation['reference']});
                } else {
                    $undo[] = [
                        'table' => $dc->table,
                        'relationTable' => $table,
                        'relationField' => $fieldName,
                        'reference' => $dc->{$relation['field']},
                        'values' => DcaRelationsModel::getReferenceValues($table, $fieldName, $dc->{$relation['field']})
                    ];

                    $this->purgeRelatedRecords($relation, $dc->{$relation['field']});
                }
            }
        }

        // Store the relations in the tl_undo table
        if (count($undo) > 0) {
            $this->undoManager->add($undoId, 'haste_relations', $undo);
        }
    }

    /**
     * Undo the relations.
     */
    public function undoRelations(array $data, int $id, string $table, array $row): void
    {
        if (!is_array($data['haste_relations']) || count($data['haste_relations']) === 0) {
            return;
        }

        foreach ($data['haste_relations'] as $relation) {
            if ($relation['table'] !== $table) {
                continue;
            }

            $relation = $this->getRelation($relation['relationTable'], $relation['relationField']);
            $isReferenceTable = $relation['reference_table'] === $table;
            $fieldName = $isReferenceTable ? $relation['reference'] : $relation['field'];

            // Continue if there is no relation or reference value does not match
            if ($relation === null || empty($relation['values']) || $relation['reference'] !== $row[$fieldName]) {
                continue;
            }

            foreach ($relation['values'] as $value) {
                $this->connection->insert($relation['table'], [
                    $relation['reference_field'] => $isReferenceTable ? $id : $value,
                    $relation['related_field'] => $isReferenceTable ? $value : $id,
                ]);
            }
        }
    }

    /**
     * Load all data containers.
     */
    protected function loadDataContainers(): void
    {
        $processed = [];

        /** @var SplFileInfo[] $files */
        $files = $this->resourceFinder->findIn('dca')->depth(0)->files()->name('*.php');

        foreach ($files as $file) {
            if (\in_array($file->getBasename(), $processed, true)) {
                continue;
            }

            $processed[] = $file->getBasename();

            Controller::loadDataContainer($file->getBasename('.php'));
        }
    }

    /**
     * Copy the records in related table.
     */
    public function copyRelatedRecords(int $id, DataContainer $dc): void
    {
        if (!isset($GLOBALS['TL_DCA'][$dc->table]['fields'])) {
            return;
        }

        foreach ($GLOBALS['TL_DCA'][$dc->table]['fields'] as $fieldName => $fieldConfig) {
            if (($fieldConfig['eval']['doNotCopy'] ?? false) || ($relation = $this->getRelation($dc->table, $fieldName)) === null) {
                continue;
            }

            $reference = $id;

            // Get the reference value (if not an ID)
            if ('id' !== $relation['reference']) {
                $referenceRecord = $this->connection->fetchOne("SELECT " . $relation['reference'] . " FROM " . $dc->table . " WHERE id=?", [$id]);

                if ($referenceRecord !== false) {
                    $reference = $referenceRecord;
                }
            }

            $values = $this->connection->fetchFirstColumn("SELECT " . $relation['related_field'] . " FROM " . $relation['table'] . " WHERE " . $relation['reference_field'] . "=?", [$dc->{$relation['reference']}]);

            foreach ($values as $value) {
                $this->connection->insert($relation['table'], [
                    $relation['reference_field'] => $reference,
                    $relation['related_field'] => $value,
                ]);
            }
        }
    }

    /**
     * Clean the records in related table.
     */
    public function cleanRelatedRecords(): void
    {
        $dc = null;

        // Try to find the \DataContainer instance (see #37)
        foreach (func_get_args() as $arg) {
            if ($arg instanceof DataContainer) {
                $dc = $arg;
                break;
            }
        }

        if ($dc === null) {
            throw new \RuntimeException('There seems to be no valid DataContainer instance!');
        }

        $this->loadDataContainers();

        foreach ($GLOBALS['TL_DCA'] as $table => $dca) {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
                continue;
            }

            foreach ($GLOBALS['TL_DCA'][$table]['fields'] as $fieldName => $fieldConfig) {
                $relation = $this->getRelation($table, $fieldName);

                if ($relation === null || $relation['related_table'] !== $dc->table) {
                    continue;
                }

                $this->connection->delete($relation['table'], [$relation['related_field'] => $dc->{$relation['field']}]);
            }
        }
    }

    #[AsHook('reviseTable')]
    public function reviseRelatedRecords(string $table, array $ids): bool
    {
        if (empty($ids) || !isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            return false;
        }

        foreach ($GLOBALS['TL_DCA'][$table]['fields'] as $fieldName => $fieldConfig) {
            if (($relation = $this->getRelation($table, $fieldName)) === null) {
                continue;
            }

            $values = $this->connection->fetchFirstColumn("SELECT " . $relation['reference'] . "FROM " . $table . " WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND tstamp=0");

            foreach ($values as $value) {
                $this->purgeRelatedRecords($relation, $value);
            }
        }

        return false;
    }

    /**
     * Get related records of particular field.
     */
    public function getRelatedRecords(mixed $value, DataContainer $dc): mixed
    {
        if (($relation = $this->getRelation($dc->table, $dc->field)) !== null) {
            $value = DcaRelationsModel::getRelatedValues($dc->table, $dc->field, $dc->{$relation['reference']});
        }

        return $value;
    }

    /**
     * Purge the related records.
     */
    protected function purgeRelatedRecords(array $relation, mixed $reference): void
    {
        $this->connection->delete($relation['table'], [$relation['reference_field'] => $reference]);
    }

    #[AsHook('sqlGetFromFile')]
    public function addRelationTables(array $definitions): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null && !$this->scopeMatcher->isFrontendRequest($request)) {
            foreach ($this->connection->createSchemaManager()->listTables() as $table) {
                $tableName = $table->getName();

                if (!str_starts_with($tableName, 'tl_')) {
                    continue;
                }

                Controller::loadDataContainer($tableName);

                if (!isset($GLOBALS['TL_DCA'][$tableName]['fields'])) {
                    continue;
                }

                foreach ($GLOBALS['TL_DCA'][$tableName]['fields'] as $fieldName => $fieldConfig) {
                    $relation = $this->getRelation($tableName, $fieldName);

                    if ($relation === null || $relation['skipInstall']) {
                        continue;
                    }

                    $definitions[$relation['table']]['TABLE_FIELDS'][$relation['reference_field']] = "`" . $relation['reference_field'] . "` " . $relation['reference_sql'];
                    $definitions[$relation['table']]['TABLE_FIELDS'][$relation['related_field']] = "`" . $relation['related_field'] . "` " . $relation['related_sql'];

                    if ($relation['related_tableSql']) {
                        $definitions[$relation['table']]['TABLE_OPTIONS'] = $relation['related_tableSql'];
                    }

                    // Add the index only if there is no other (avoid duplicate keys)
                    if (empty($definitions[$relation['table']]['TABLE_CREATE_DEFINITIONS'])) {
                        $definitions[$relation['table']]['TABLE_CREATE_DEFINITIONS'][$relation['reference_field'] . "_" . $relation['related_field']] = "UNIQUE KEY `" . $relation['reference_field'] . "_" . $relation['related_field'] . "` (`" . $relation['reference_field'] . "`, `" . $relation['related_field'] . "`)";
                    }
                }
            }
        }

        return $definitions;
    }

    /**
     * Filter records by relations set in custom filter.
     */
    public function filterByRelations(DataContainer $dc): void
    {
        if (count($this->filterableFields) === 0 || ($request = $this->requestStack->getCurrentRequest()) === null) {
            return;
        }

        $rootIds = isset($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root']) && \is_array($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root']) ? $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] : [];

        // Include the child records in tree view
        if (($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['mode'] ?? null) === DataContainer::MODE_TREE && count($rootIds) > 0) {
            $rootIds = Database::getInstance()->getChildRecords($rootIds, $dc->table, false, $rootIds);
        }

        $doFilter = false;
        $sessionData = $request->getSession()->getBag('contao_backend')->all();
        $filterId = (($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['mode'] ?? null) === DataContainer::MODE_PARENT) ? $dc->table.'_'.$dc->currentPid : $dc->table;

        foreach (array_keys($this->filterableFields) as $field) {
            if (isset($sessionData['filter'][$filterId][$field])) {
                $doFilter = true;
                $ids = DcaRelationsModel::getReferenceValues($dc->table, $field, $sessionData['filter'][$filterId][$field]);
                $rootIds = (count($rootIds) === 0) ? $ids : array_intersect($rootIds, $ids);
            }
        }

        if ($doFilter) {
            $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] = (count($rootIds) === 0) ? [0] : array_unique($rootIds);
        }
    }

    /**
     * Filter records by relation search.
     */
    public function filterBySearch(DataContainer $dc): void
    {
        if (count($this->searchableFields) === 0 || ($request = $this->requestStack->getCurrentRequest()) === null) {
            return;
        }

        $rootIds = is_array($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] ?? null) ? $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] : [];
        $doFilter = false;
        $sessionData = $request->getSession()->getBag('contao_backend')->all();

        foreach ($this->searchableFields as $field => $relation) {
            $relatedTable = $relation['related_table'];

            Controller::loadDataContainer($relatedTable);

            if (isset($sessionData['haste_search'][$dc->table])
                && '' !== $sessionData['haste_search'][$dc->table]['searchValue']
                && $relatedTable == $sessionData['haste_search'][$dc->table]['table']
                && $field == $sessionData['haste_search'][$dc->table]['field']
            ) {
                $doFilter = true;
                $query = sprintf('SELECT %s.%s AS sourceId FROM %s INNER JOIN %s ON %s.%s = %s.%s INNER JOIN %s ON %s.%s = %s.%s',
                    $dc->table,
                    $relation['reference'],
                    $dc->table,
                    $relation['table'],
                    $dc->table,
                    $relation['reference'],
                    $relation['table'],
                    $relation['reference_field'],
                    $relation['related_table'],
                    $relation['related_table'],
                    $relation['field'],
                    $relation['table'],
                    $relation['related_field']
                );

                $procedure = [];
                $values = [];

                $strPattern = "CAST(%s AS CHAR) REGEXP ?";

                if (str_ends_with(Config::get('dbCollation'), '_ci')) {
                    $strPattern = "LOWER(CAST(%s AS CHAR)) REGEXP LOWER(?)";
                }

                $fld = $relation['related_table'] . '.' . $sessionData['haste_search'][$dc->table]['searchField'];

                if (isset($GLOBALS['TL_DCA'][$relatedTable]['fields'][$fld]['foreignKey'])) {
                    [$t, $f] = explode('.', $GLOBALS['TL_DCA'][$relatedTable]['fields'][$fld]['foreignKey']);
                    $procedure[] = "(" . sprintf($strPattern, $fld) . " OR " . sprintf($strPattern, "(SELECT $f FROM $t WHERE $t.id={$relatedTable}.$fld)") . ")";
                    $values[] = $sessionData['haste_search'][$dc->table]['searchValue'];
                } else {
                    $procedure[] = sprintf($strPattern, $fld);
                }

                $values[] = $sessionData['haste_search'][$dc->table]['searchValue'];

                $query .= ' WHERE ' . implode(' AND ', $procedure);

                $ids = $this->connection->fetchAllAssociative($query, $values);
                $ids = array_column($ids, 'sourceId');

                $rootIds = (count($rootIds) === 0) ? $ids : array_intersect($rootIds, $ids);
            }
        }

        if ($doFilter) {
            $GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['root'] = (count($rootIds) === 0) ? [0] : array_unique($rootIds);
        }
    }

    /**
     * Add the relation filters.
     */
    public function addRelationFilters(DataContainer $dc): string
    {
        if (count($this->filterableFields) === 0 || ($request = $this->requestStack->getCurrentRequest()) === null) {
            return '';
        }

        $filter = (($GLOBALS['TL_DCA'][$dc->table]['list']['sorting']['mode'] ?? null) === DataContainer::MODE_PARENT) ? $dc->table.'_'.$dc->currentPid : $dc->table;

        /** @var AttributeBagInterface $session */
        $session = $request->getSession()->getBag('contao_backend');
        $sessionData = $session->all();

        // Set filter from user input
        if ('tl_filters' === Input::post('FORM_SUBMIT')) {
            foreach (array_keys($this->filterableFields) as $field) {
                if (Input::post($field, true) !== 'tl_' . $field) {
                    $sessionData['filter'][$filter][$field] = Input::post($field, true);
                } else {
                    unset($sessionData['filter'][$filter][$field]);
                }
            }

            $session->replace($sessionData);
        }

        $count = 0;
        $return = '<div class="tl_filter tl_subpanel">
<strong>' . $GLOBALS['TL_LANG']['HST']['advanced_filter'] . '</strong> ';

        foreach ($this->filterableFields as $field => $relation) {
            $return .= '<select name="' . $field . '" class="tl_select tl_chosen' . (isset($session['filter'][$filter][$field]) ? ' active' : '') . '">
    <option value="tl_' . $field . '">' . ($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['label'][0] ?? '') . '</option>
    <option value="tl_' . $field . '">---</option>';

            $ids = DcaRelationsModel::getRelatedValues($relation['reference_table'], $field);

            if (count($ids) === 0) {
                $return .= '</select> ';

                // Add the line-break after 5 elements
                if ((++$count % 5) == 0) {
                    $return .= '<br>';
                }

                continue;
            }

            $options = array_unique($ids);
            $options_callback = [];

            // Store the field name to be used e.g. in the options_callback
            $dc->field = $field;

            // Call the options_callback
            if ((is_array($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback'] ?? null) || is_callable($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback'] ?? null)) && !($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['reference'] ?? null)) {
                if (is_array($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback'] ?? null)) {
                    $class = $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback'][0];
                    $method = $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback'][1];

                    $options_callback = System::importStatic($class)->$method($dc);
                } elseif (is_callable($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback'] ?? null)) {
                    $options_callback = $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options_callback']($dc);
                }

                // Sort options according to the keys of the callback array
                $options = array_intersect(array_keys($options_callback), $options);
            }

            $options_sorter = [];

            // Options
            foreach ($options as $vv) {
                $value = $vv;

                // Options callback
                if (!empty($options_callback) && is_array($options_callback)) {
                    $vv = $options_callback[$vv];
                } elseif (isset($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['foreignKey'])) {
                    // Replace the ID with the foreign key
                    $key = explode('.', $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['foreignKey'], 2);

                    $parent = $this->connection->fetchOne("SELECT " . $key[1] . " FROM " . $key[0] . " WHERE id=?", [$vv]);

                    if ($parent !== false) {
                        $vv = $parent;
                    }
                }

                $option_label = '';

                // Use reference array
                if (isset($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['reference'])) {
                    $option_label = is_array($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['reference'][$vv]) ? $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['reference'][$vv][0] : $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['reference'][$vv];
                } elseif (($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['eval']['isAssociative'] ?? false) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options'] ?? null)) {
                    // Associative array
                    $option_label = $GLOBALS['TL_DCA'][$dc->table]['fields'][$field]['options'][$vv] ?? '';
                }

                // No empty options allowed
                if (!strlen($option_label)) {
                    $option_label = $vv ?: '-';
                }

                $options_sorter['  <option value="' . StringUtil::specialchars($value) . '"' . ((isset($session['filter'][$filter][$field]) && $value === $session['filter'][$filter][$field]) ? ' selected="selected"' : '').'>'.$option_label.'</option>'] = (new UnicodeString($option_label))->ascii()->toString();
            }

            $return .= "\n" . implode("\n", array_keys($options_sorter));
            $return .= '</select> ';

            // Add the line-break after 5 elements
            if ((++$count % 5) === 0) {
                $return .= '<br>';
            }
        }

        return $return . '</div>';
    }

    /**
     * Adds search fields for relations.
     */
    public function addRelationSearch(DataContainer $dc): string
    {
        if (count($this->searchableFields) === 0 || ($request = $this->requestStack->getCurrentRequest()) === null) {
            return '';
        }

        $return = '<div class="tl_filter tl_subpanel">';

        /** @var AttributeBagInterface $session */
        $session = $request->getSession()->getBag('contao_backend');
        $sessionValues = $session->get('haste_search');

        // Search field per relation
        foreach ($this->searchableFields as $field => $relation) {

            // Get searchable fields from related table
            $relatedSearchFields = [];
            $relTable = $relation['related_table'];

            Controller::loadDataContainer($relTable);
            foreach ((array) $GLOBALS['TL_DCA'][$relTable]['fields'] as $relatedField => $dca) {
                if (isset($dca['search']) && true === $dca['search']) {
                    $relatedSearchFields[] = $relatedField;
                }
            }

            if (0 === count($relatedSearchFields)) {
                continue;
            }

            // Store search value in the current session
            if (Input::post('FORM_SUBMIT') == 'tl_filters') {
                $fieldName = Input::post('tl_field_' . $field, true);
                $keyword = ltrim(Input::postRaw('tl_value_' . $field), '*');

                if ($fieldName && !\in_array($fieldName, $relatedSearchFields, true)) {
                    $fieldName = '';
                    $keyword = '';
                }

                // Make sure the regular expression is valid
                if ($fieldName && $keyword) {
                    try {
                        $this->connection->fetchOne("SELECT id FROM " . $relTable . " WHERE " . $fieldName . " REGEXP ? LIMIT 1", [$keyword]);
                    } catch (\Exception $e) {
                        $keyword = '';
                    }
                }

                $session->set('haste_search', [$dc->table => [
                    'field' => $field,
                    'table' => $relTable,
                    'searchField' => $fieldName,
                    'searchValue' => $keyword,
                ]]);
            }

            $return .= '<div class="tl_search tl_subpanel">';
            $return .= '<strong>'.sprintf($GLOBALS['TL_LANG']['HST']['advanced_search'], $this->formatter->dcaLabel($dc->table, $field)).'</strong> ';

            $options_sorter = [];
            foreach ($relatedSearchFields as $relatedSearchField) {
                $option_label = $GLOBALS['TL_DCA'][$relTable]['fields'][$relatedSearchField]['label'][0] ?: (\is_array($GLOBALS['TL_LANG']['MSC'][$relatedSearchField] ?? null) ? $GLOBALS['TL_LANG']['MSC'][$relatedSearchField][0] : ($GLOBALS['TL_LANG']['MSC'][$relatedSearchField] ?? ''));
                $options_sorter[(new UnicodeString($option_label))->ascii()->toString().'_'.$relatedSearchField] = '  <option value="'.StringUtil::specialchars($relatedSearchField).'"'.(($relatedSearchField === $sessionValues[$dc->table]['searchField'] && $sessionValues[$dc->table]['table'] === $relTable) ? ' selected="selected"' : '').'>'.$option_label.'</option>';
            }

            // Sort by option values
            uksort($options_sorter, 'strnatcasecmp');
            $active = $sessionValues[$dc->table]['searchValue'] && $sessionValues[$dc->table]['table'] === $relTable;

            $return .= '<select name="tl_field_' . $field . '" class="tl_select tl_chosen' . ($active ? ' active' : '') . '">
            '.implode("\n", $options_sorter).'
            </select>
            <span>=</span>
            <input type="search" name="tl_value_' . $field . '" class="tl_text' . ($active ? ' active' : '') . '" value="'.StringUtil::specialchars($sessionValues[$dc->table]['searchValue']).'"></div>';
        }

        return $return . '</div>';
    }

    /**
     * Get the relation of particular field in the table.
     */
    public function getRelation(string $table, string $fieldName): ?array
    {
        Controller::loadDataContainer($table);

        $cacheKey = $table . '_' . $fieldName;

        if (!array_key_exists($cacheKey, $this->relationsCache)) {
            $relation = null;

            if (isset($GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['relation'])) {
                $fieldConfig = &$GLOBALS['TL_DCA'][$table]['fields'][$fieldName]['relation'];

                if (isset($fieldConfig['table']) && 'haste-ManyToMany' === $fieldConfig['type']) {
                    $relation = [];

                    // The relations table
                    $relation['table'] = $fieldConfig['relationTable'] ?? $this->getTableName($table, $fieldConfig['table']);

                    // The related field
                    $relation['reference'] = $fieldConfig['reference'] ?? 'id';
                    $relation['field'] = $fieldConfig['field'] ?? 'id';

                    // Current table data
                    $relation['reference_table'] = $table;
                    $relation['reference_field'] = $fieldConfig['referenceColumn'] ?? (str_replace('tl_', '', $table).'_'.$relation['reference']);
                    $relation['reference_sql'] = $fieldConfig['referenceSql'] ?? "int(10) unsigned NOT NULL default '0'";

                    // Related table data
                    $relation['related_table'] = $fieldConfig['table'];
                    $relation['related_tableSql'] = $fieldConfig['tableSql'] ?? null;
                    $relation['related_field'] = $fieldConfig['fieldColumn'] ?? (str_replace('tl_', '', $fieldConfig['table']).'_'.$relation['field']);
                    $relation['related_sql'] = $fieldConfig['fieldSql'] ?? "int(10) unsigned NOT NULL default '0'";

                    // Force save
                    $relation['forceSave'] = $fieldConfig['forceSave'] ?? null;

                    // Bidirectional
                    $relation['bidirectional'] = true; // I'm here for BC only

                    // Do not add table in install tool
                    $relation['skipInstall'] = (bool) ($fieldConfig['skipInstall'] ?? false);
                }
            }

            $this->relationsCache[$cacheKey] = $relation;
        }

        return $this->relationsCache[$cacheKey];
    }

    /**
     * Get the relations table name in the following format (sorted alphabetically):
     * Parameters: tl_table_one, tl_table_two
     * Returned value: tl_table_one_table_two
     */
    public function getTableName(string $tableOne, string $tableTwo): string
    {
        $tables = [$tableOne, $tableTwo];
        natcasesort($tables);
        $tables = array_values($tables);

        return $tables[0] . '_' . str_replace('tl_', '', $tables[1]);
    }
}