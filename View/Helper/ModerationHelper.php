<?php
App::uses('AppHelper', 'View/Helper');

class ModerationHelper extends AppHelper {
    public $helpers = array('Html');

/**
 * Approve a record
 *
 * @param  int $id      ID of record to approve
 * @param  array  $options Options passed to the html link
 * @return string          Url
 */
    public function approve($id, $options = array()) {
        return $this->Html->link('Approve', array('action' => 'moderate', $id, 'approved'), $options);
    }

/**
 * Reject a record
 * @param  int $id      ID of record
 * @param  array  $options Options passed to HTML helper
 * @return string          Url
 */
    public function reject($id, $options = array()) {
        return $this->Html->link('Reject', array('action' => 'moderate', $id, 'rejected'), $options);
    }
}