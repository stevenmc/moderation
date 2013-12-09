Enable the plugin:
CakePlugin::load('Moderation');

Add a field called `moderation_status` to your table (Can be changed in the behavior settings)

Add the behavior to your model:
    public $actsAs = array(
        'Moderation.Moderation'
    );

Add the helper and a moderate method to your controller:

    public $helpers = array('Moderation.Moderation');

    public function admin_moderate($id = null, $status) {
        $this->{Model}->id = $id;
        if (!$this->{Model}n->exists()) {
            throw new NotFoundException(__('Invalid record'));
        }

        if($this->{Model}->moderate($id, $status)) {
            $this->flashMessage(__('The record has been moderated'), 'alert-success', array('action' => 'index'));
        }
        $this->flashMessage(__('The record could not be moderated. Please, try again.'), 'alert-error');
    }


In your view you can use the helpers like this:
<?php echo $this->Moderation->approve($this->request->data[{Model}]['id']);?>
<?php echo $this->Moderation->reject($this->request->data[{Model}]['id']);?>