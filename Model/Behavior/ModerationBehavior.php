<?php
App::uses('CakeEvent', 'Event');

class ModerationBehavior extends ModelBehavior {

/**
 * Default settings for the model.
 *
 * TODO: Implement auto_approve and Auto_find_filter
 * @var array
 */
    protected $_defaults = array(
        'field' => 'moderation_status',
        'states' => array(
            'none' => 'none',
            'pending' => 'pending',
            'rejected' => 'rejected',
            'approved' => 'approved',
        ),
        'auto_approve' => false,
        'auto_find_filter' => false
    );

    public $mapMethods = array(
        '/\b_findPending\b/' => '_findPending',
        '/\b_findApproved\b/' => '_findApproved',
        '/\b_findRejected\b/' => '_findRejected',
    );

/**
 * Initiate behaviour for the model using settings.
 *
 * @param object $Model Model using the behaviour
 * @param array $settings Settings to override for model.
 */
    public function setup(Model $Model, $settings = array()) {
        $this->settings[$Model->alias] = array_merge($this->_defaults, (array)$settings);
        $Model->findMethods['pending'] = true;
        $Model->findMethods['approved'] = true;
        $Model->findMethods['rejected'] = true;
    }

/**
 * Moderate a record for the specific model.
 *
 * @param object $Model Model to be moderated
 * @param boolean $id ID of record to be moderated
 * @param string $status Status the moderation field wll be set to.
 * @param array $attributes Other fields to change (in the form of field => value)
 * @return boolean Result of the operation.
 */
    public function moderate(Model $Model, $id = null, $status = null, $attributes = array()) {
        if (!$Model->hasField($this->settings[$Model->alias]['field'])) {
            throw new CakeException(sprintf(__d('Moderation', 'The model requires a `%s` field.'), $this->settings[$Model->alias]['field']), E_USER_ERROR);
        }

        if (empty($id)) {
            $id = $Model->id;
        }

        if (empty($status) || !in_array($status, $this->settings[$Model->alias]['states'])) {
            throw new InvalidArgumentException(sprintf(__d('Moderation', 'The state `%s` is not in the list of states associated with this behavior.'), $status), E_USER_ERROR);
        }

        $onFind = $this->settings[$Model->alias]['auto_find_filter'];
        $this->enableModeration($Model, false);

        $data = array(
            $Model->alias => array(
                $Model->primaryKey => $id,
                $this->settings[$Model->alias]['field'] => $status
            )
        );

        $Model->id = $id;

        if (!empty($attributes)) {
            $data[$Model->alias] = array_merge($data[$Model->alias], $attributes);
        }

        $result = $Model->save($data, false, array_keys($data[$Model->alias]));
        $this->enableModeration($Model, $onFind);

        $Model->getEventManager()->dispatch(new CakeEvent('Model.Moderation.moderate', $Model, compact('data')));

        return ($result !== false);
    }


/**
 * Set if the beforeFind() should be overriden for specific model.
 *
 * @param object $Model Model to be published
 * @param boolean $enable If specified method should be overriden.
 */
    public function enableModeration(Model $Model, $enable = true) {
        $this->settings[$Model->alias]['auto_find_filter'] = $enable;
    }

/**
 * Run before a model is about to be find, used only fetch for moderated records.
 *
 * @param object $Model Model
 * @param array $queryData Data used to execute this query, i.e. conditions, order, etc.
 * @return mixed Set to false to abort find operation, or return an array with data used to execute query
 */
    public function beforeFind(Model $Model, $queryData, $recursive = null) {

        if (Configure::read('Moderation.disabled') === true) {
            return $queryData;
        }

        if ($this->settings[$Model->alias]['auto_find_filter']
            && $Model->hasField($this->settings[$Model->alias]['field'])) {

            $Db = ConnectionManager::getDataSource($Model->useDbConfig);
            $include = false;

            if (!empty($queryData['conditions']) && is_string($queryData['conditions'])) {
                $include = true;

                $fields = array(
                    $Db->name($Model->alias) . '.' . $Db->name($this->settings[$Model->alias]['field']),
                    $Db->name($this->settings[$Model->alias]['field']),
                    $Model->alias . '.' . $this->settings[$Model->alias]['field'],
                    $this->settings[$Model->alias]['field']
                );

                foreach ($fields as $field) {
                    if (preg_match('/^' . preg_quote($field) . '[\s=!]+/i', $queryData['conditions']) ||
                        preg_match('/\\x20+' . preg_quote($field) . '[\s=!]+/i', $queryData['conditions'])) {

                        $include = false;
                        break;
                    }
                }
            } else if (empty($queryData['conditions']) ||
                (!in_array($this->settings[$Model->alias]['field'], array_keys($queryData['conditions'])) &&
                !in_array($Model->alias . '.' . $this->settings[$Model->alias]['field'], array_keys($queryData['conditions'])))) {

                $include = true;
            }

            if ($include) {
                if (empty($queryData['conditions'])) {
                    $queryData['conditions'] = array();
                }

                if (is_string($queryData['conditions'])) {
                    $queryData['conditions'] = $Db->name($Model->alias) . '.' . $Db->name($this->settings[$Model->alias]['field']) . '= 1 AND ' . $queryData['conditions'];
                } else {
                    $queryData['conditions'][$Model->alias . '.' . $this->settings[$Model->alias]['field']] = $this->settings[$Model->alias]['states']['approved'];
                }
            }

            if (is_null($recursive) && !empty($queryData['recursive'])) {
                $recursive = $queryData['recursive'];
            } else if (is_null($recursive)) {
                $recursive = $Model->recursive;
            }

            if ($recursive < 0) {
                return $queryData;
            }

            $associated = $Model->getAssociated('belongsTo');

            foreach ($associated as $m) {
                if ($Model->{$m}->Behaviors->enabled('Moderation')) {
                    $queryData = $Model->{$m}->Behaviors->Moderation->beforeFind($Model->{$m}, $queryData, --$recursive);
                }
            }

        }

        return $queryData;
    }


/**
 * Run before a model is saved, used to disable beforeFind() override.
 *
 * @param object $Model Model about to be saved.
 * @return boolean True if the operation should continue, false if it should abort
 */
    public function beforeSave(Model $Model, $options = array()) {
        if ($this->settings[$Model->alias]['auto_find_filter']) {
            if (!isset($this->backAttributes)) {
                $this->backAttributes = array($Model->alias => array());
            }
            else if (!isset($this->backAttributes[$Model->alias])) {
                $this->backAttributes[$Model->alias] = array();
            }

            $this->backAttributes[$Model->alias]['auto_find_filter'] = $this->settings[$Model->alias]['auto_find_filter'];
            $this->enableModeration($Model, false);
        }

        return true;
    }

/**
 * Run after a model has been saved, used to enable beforeFind() override.
 *
 * @param object $Model Model just saved.
 * @param boolean $created True if this save created a new record
 */
    public function afterSave(Model $Model, $created, $options = array()) {
        if (isset($this->backAttributes[$Model->alias]['auto_find_filter'])) {
            $this->enableModeration($Model, 'find', $this->backAttributes[$Model->alias]['auto_find_filter']);
            unset($this->backAttributes[$Model->alias]['auto_find_filter']);
        }
    }

    /**
     * Pending projects
     *
     * @param string $state Either "before" or "after"
     * @param array $query
     * @param array $results
     * @return array
     */
    public function _findPending(Model $Model, $method, $state, $query, $results = array()) {
        if ($state === 'before') {
            $defaults = array(
                'conditions' => array(
                    $Model->alias . '.' . $this->settings[$Model->alias]['field'] => 'pending',
                ),
                'recursive' => 0
            );
            return array_merge_recursive($defaults, $query);
        }
        return $results;
    }

    /**
     * Approved projects
     *
     * @param string $state Either "before" or "after"
     * @param array $query
     * @param array $results
     * @return array
     */
    public function _findApproved(Model $Model, $method, $state, $query, $results = array()) {
        if ($state === 'before') {
            $defaults = array(
                'conditions' => array(
                    $Model->alias . '.' . $this->settings[$Model->alias]['field'] => 'approved',
                ),
                'recursive' => 0
            );
            return array_merge_recursive($defaults, $query);
        }
        return $results;
    }

    /**
     * Rejected projects
     *
     * @param string $state Either "before" or "after"
     * @param array $query
     * @param array $results
     * @return array
     */
    public function _findRejected(Model $Model, $method, $state, $query, $results = array()) {
        if ($state === 'before') {
            $defaults = array(
                'conditions' => array(
                    $Model->alias . '.' . $this->settings[$Model->alias]['field'] => 'rejected',
                ),
                'recursive' => 0
            );
            return array_merge_recursive($defaults, $query);
        }
        return $results;
    }

}