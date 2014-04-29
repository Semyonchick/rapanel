<?php

class ContentBehavior extends AdminBehavior
{
    private $_module;
    private $_isCategory;
    private $_dataProvider;

    protected function getAdminSettings()
    {
        $adminSettings = (array)$this->getModule()->config;
        return count($adminSettings) ? $adminSettings : array(
            'actions' => array('view', 'edit', 'delete'),
        );
    }

    public function getDataProvider()
    {
        if (!$this->_dataProvider) {
            $columns = $this->adminSettings['columns'];
            $criteria = new CDbCriteria();
            $criteria->addCondition('`t`.`id` > 0');

            if (isset($this->owner->module_id))
                $criteria->compare('`t`.`module_id`', $this->getModule()->id);

            if (method_exists($this->getOwner(), 'getCharacterNames')) {
                $characters = array_intersect((array)$this->adminSettings['columns'], $this->getOwner()->getCharacterNames());
                foreach ($characters as $character) $criteria->with[] = Characters::getRelationByUrl($character);
                if ((int)$this->adminSettings['photos'] > 0)
                    $criteria->with['photo'] = array('select' => 'name');
                $criteria->with['currentUrl'] = array('select' => 'value');
            }

            $relations = array_keys($this->owner->relations());
            if (in_array('user_id', $columns) && in_array('user', $relations))
                $criteria->with['user'] = array('select' => 'username');
            if (in_array('page_id', $columns) && in_array('page', $relations))
                $criteria->with['page'] = array('select' => false, 'with' => array('rName' => array('alias' => 'pName')));
            if (in_array('parent_id', $columns) && in_array('parent', $relations))
                $criteria->with['parent'] = array('select' => false, 'with' => array('rName' => array('alias' => 'pName')));

            foreach ((array)$criteria->with as $key => $with)
                if (is_array($with))
                    $criteria->with[$key] = array_merge($with, array('together' => false));
                else {
                    unset($criteria->with[$key]);
                    $criteria->with[$with] = array('together' => false);
                }

            if ($_GET['limit']) {
                $_GET['myPage'] = floor($_GET['start'] / $_GET['limit']) + 1;
                $pagination = array(
                    'pageSize' => $_GET['limit'],
                    'pageVar' => 'myPage',
                );
            } else $pagination = false;

            $sort = false;

            if ($this->getModule()->type_id == Module::TYPE_SELF_NESTED || $this->getModule()->type_id == Module::TYPE_NESTED)
                $criteria->order = '`t`.`lft` ASC, `t`.`id` DESC';
            elseif (in_array('num', array_keys($this->getOwner()->tableSchema->columns)))
                $criteria->order = '`t`.`num` ASC, `t`.`id` ASC';
            else
                $sort = array('defaultOrder' => 't.id DESC');
            $criteria->group = '`t`.`id`';
            $criteria->limit = 50;

            $criteria = $this->getSearchCriteria($criteria);

            $this->_dataProvider = new CActiveDataProvider($this->owner->cache('60*60', new CGlobalStateCacheDependency($this->getModule()->url)), compact('criteria', 'pagination', 'sort'));

            /* $count = Yii::app()->cache->get( $id = md5(serialize($criteria)));
             if($count === false){
                 if($_GET['q'])
                     else $count = $this->owner->getCommandBuilder()->createCountCommand($this->owner->getTableSchema(),$criteria,$this->owner->getTableAlias())->queryScalar();
                 Yii::app()->cache->set($id, $count, 5*60);
             }
             $this->_dataProvider->setTotalItemCount($count);*/
        }

        return $this->_dataProvider;
    }

    /** @var $criteria CDbCriteria */
    public function getSearchCriteria($criteria)
    {
        if ($q = $_GET['q']) {
            $condition = array();
            foreach ($this->getOwner()->tableSchema->columns as $row) {
                if (in_array(current(explode('(', $row->dbType)), array('varchar', 'text'))) {
                    $condition[] = "t.{$row->name} LIKE :textSearch";
                    $criteria->params['textSearch'] = "%{$q}%";
                } elseif (in_array(current(explode('(', $row->dbType)), array('timestamp'))) {
                    continue;
                } else {
                    if (!is_numeric($q)) continue;
                    $condition[] = "t.{$row->name}=:intSearch";
                    $criteria->params['intSearch'] = $q;
                }
            }
            if (get_class($this->getOwner()) == 'Page' || $this->getOwner() instanceof Page) {
                $criteria->join .= ' INNER JOIN `character_varchar` `cv` ON(cv.page_id=t.id)';
                $condition[] = "cv.value LIKE :textSearch";
                $criteria->params['textSearch'] = "%{$q}%";
            }
            if (count($criteria->with)) foreach (array('user', 'currentUrl') as $val) if (isset($criteria->with[$val]) && !empty($criteria->with[$val]['select'])) {
                $condition[] = "{$val}.{$criteria->with[$val]['select']} LIKE :textSearch";
                $criteria->with[$val]['together'] = true;
                $criteria->params['textSearch'] = "%{$q}%";
            }
            if (count($criteria->with)) foreach (array('page', 'parent') as $val) if (isset($criteria->with[$val]) && $criteria->with[$val]['with']['rName']) {
                $condition[] = "pName.value LIKE :textSearch";
                $criteria->with[$val]['together'] = true;
                $criteria->params['textSearch'] = "%{$q}%";
            }
            $criteria->addCondition(implode(' OR ', $condition));
        }

        return $criteria;
    }

    public function getColumns()
    {
        /** @var $owner PageBase */
        $owner = $this->getOwner();

        $default = array();
        $default['order'] = array(
            'name' => '#',
            'value' => '$data->id',
        );
        $default['checkbox'] = array(
            'class' => 'CCheckBoxColumn',
            'selectableRows' => 9999,
        );
        foreach ((array)$this->adminSettings['columns'] as $column) {
            $default[$column] = array(
                'header' => $owner->getAttributeLabel($column),
                'value' => '$data->' . $column . '',
            );
            if ($column == 'user_id') $default[$column]['value'] = '$data->user->username';
            if ($column == 'page_id') $default[$column]['value'] = '$data->page->name';
            if ($column == 'parent_id') $default[$column]['value'] = '$data->parent->name';
            if ($column == 'status_id') $default[$column]['value'] = '$data->status';
        }
        $default['buttons'] = array(
            'header' => 'Действия',
            'class' => 'CButtonColumn',
            'template' => '{' . implode('} {', $this->adminSettings['actions']) . '}',
            'buttons' => $this->getButtons(),
        );
        return $default;
    }

    public function getButtons()
    {
        $result = array();
        foreach ($this->adminSettings['actions'] as $button) {
            $result[$button] = array(
                'label' => ucfirst($button),
                'url' => 'CHtml::normalizeUrl(array("' . $button . '", "url"=>"' . $this->getModule()->url . '", "id"=>$data->id))',
                'imageUrl' => false,
                'crop' => $this->adminSettings['crop'],
                'max' => $this->adminSettings['photos'],
                'options' => array(
                    'class' => "button" . ucfirst($button),
                    'onclick' => 'modalIFrame(this);return false;',
                    'data-update' => 'contentGrid',
                ),
            );
        }
        return $result;
    }

    public function getForm()
    {
        return array(
            'showErrorSummary' => true,
            'elements' => $this->getElements(),
            'buttons' => array(
                'send' => array(
                    'type' => 'submit',
                    'label' => Yii::t('admin', $this->owner->isNewRecord ? 'Create' : 'Save'),
                ),
            ),
        );
    }

    /**
     * @return array|mixed
     */
    public function getElements()
    {
        $result = array();
        $elements = method_exists($this->owner, 'getElements') ? $this->owner->getElements() : array();
        foreach ((array)$this->adminSettings['elements'] as $row)
            if (in_array($row, array_keys($this->getOwner()->tableSchema->columns))) {
                $result['main'][$row] = $elements[$row] ? $elements[$row] : array();
            }
        if (method_exists($this->getOwner(), 'getCharacterNames'))
            foreach (Characters::getDataByUrls($this->getOwner()->getCharacterNames(true)) as $row)
                $result[$row['position']][$row['url']] = $this->getCharacterElement($row);
        if (method_exists($this->getOwner(), 'settingNames'))
            foreach ($this->getOwner()->settingNames() as $row)
                $result['additional'][$row] = array(
                    'name' => $row,
                    'type' => 'text',
                );
        if ($this->adminSettings['photos'] > 0) {
            $result['photos']['photo'] = Yii::app()->controller->widget('ext.RUpload.RPhotoUpload', array(
                'model' => $this->owner,
                'attribute' => 'photos',
                'crop' => $this->adminSettings['crop'],
                'max' => $this->adminSettings['photos'],
                'options' => array(
                    'url' => array('content/upload', 'model' => 'Photo'),
                ),
            ), 1);
        }
        if ($this->adminSettings['files'] > 0) {
            $result['files']['file'] = Yii::app()->controller->widget('ext.RUpload.RFileUpload', array(
                'model' => $this->owner,
                'attribute' => 'files',
                'max' => $this->adminSettings['files'],
                'options' => array(
                    'url' => array('content/upload', 'type' => 'UserFiles'),
                ),
            ), 1);
        }
        if (method_exists($this->getOwner(), 'getUrl'))
            $result['seo']['url'] = $this->getCharacterElement(array(
                'url' => 'url',
                'label' => 'url',
                'inputType' => 'text',
            ));

        if (count($keys = array_keys($result)) == 1)
            return current($result);
        $data = array();
        $data[] = CHtml::openTag('div', array('id' => 'tabs'));
        $data[] = CHtml::openTag('ul');
        foreach ($keys as $key)
            $data[] = CHtml::tag('li', array(), CHtml::link($key, "#{$key}"));
        $data[] = CHtml::closeTag('ul');
        foreach ($result as $key => $row) {
            $data[] = CHtml::openTag('div', array('id' => $key));
            foreach ($row as $element => $value)
                $data[$element] = $value;
            $data[] = CHtml::closeTag('div');
        }
        $data[] = CHtml::closeTag('div');

        return $data;
    }

    public function getCharacterElement($row)
    {
        $data = array(
            'name' => $row['url'],
            'label' => $row['name'],
        );
        switch ($row['inputType']):
            case 'tags':
                return $data + array(
                    'type' => 'ext.RTagsInput.RTagsInput',
                    'cssFile' => false,
                    'query' => '.input-' . $row['inputType'],
                    'autoComplete' => array('autocompete', 'tag' => $row['id']),
                    'class' => 'input-' . $row['inputType'],
                );
            case 'wysiwyg':
                return $data + array(
                    'type' => 'ext.RTinyMCE.RTinyMCE',
                    'query' => '.input-' . $row['inputType'],
                    'class' => 'input-' . $row['inputType'],
                );
            case 'autocomplete':
                return $data + array(
                    'type' => 'zii.widgets.jui.CJuiAutoComplete',
//                    'query' => '.input-' . $row['inputType'],
                    'sourceUrl' => array('autocompete', 'tag' => $row['id']),
                    'cssFile' => false,
                );
            case 'date':
                return $data + array(
                    'type' => 'zii.widgets.jui.CJuiDatePicker',
                    'language' => Yii::app()->language,
                    'options' => array(
                        'dateFormat' => 'dd.mm.yy',
                        'changeMonth' => 'true',
                        'changeYear' => 'true',
                        'firstDay' => '1',
                    ),
                    'htmlOptions' => array('class' => 'datePicker'),
                    'layout' => '{label}{input}{hint}{error}',
                    'cssFile' => false,
                );
            case 'boolean':
                return $data + array(
                    'type' => 'checkbox',
                    'layout' => '{input}{label}{hint}{error}',
                    'class' => 'input-' . $row['inputType'],
                );
            case 'fromlist':
                return $data + array(
                    'type' => 'ext.RChosen.RChosen',
                    'query' => '.input-' . $row['inputType'],
                    'data' => $this->getDataList($row['data']),
//                    'autoCompleteUrl' => array('autocompete'),
                    'options' => array(
                        'disable_search_threshold' => 10,
                    ),
                    'cssFile' => false,
                    'class' => 'input-' . $row['inputType'],
                );
            case 'tagsList':
                return $data + array(
                    'type' => 'ext.RChosen.RChosen',
                    'empty' => '',
                    'multiple' => true,
                    'query' => '.input-' . $row['inputType'],
                    'data' => $this->getDataList($row['data']),
                    'cssFile' => false,
                    'class' => 'input-' . $row['inputType'],
                );
            case 'numeric':
                return $data + array(
                    'type' => 'number',
                    'class' => 'input-' . $row['inputType'],
                );
            case 'price':
                return $data + array(
                    'type' => 'number',
                    'step' => '0.01',
                    'class' => 'input-' . $row['inputType'],
                );
            default:
                return $data + array(
                    'type' => $row['inputType'],
                    'class' => 'input-' . $row['inputType'],
                );
        endswitch;
    }

    public function setModule($data)
    {
        $this->_module = $data;
        if (in_array('module_id', array_keys($this->owner->tableSchema->columns)))
            $this->owner->module_id = $data->id;
    }

    public function getModule()
    {
        return $this->_module;
    }

    public function setIsCategory($data)
    {
        $this->_isCategory = $data;
    }

    public function getIsCategory()
    {
        return $this->_isCategory;
    }

    /**
     * @return RActiveRecord.
     */
    public function getOwner()
    {
        return parent::getOwner();
    }

    public function getDataList($data)
    {
        if (stristr($data, 'SELECT') === false) {
            $items = array();
            foreach (explode(',', $data) as $item) $items[trim($item)] = trim($item);
        } else {
            $items = CHtml::listData(Yii::app()->db->createCommand($data)->queryAll(), 'key', 'value');
        }
        return $items;
    }
}