<?php

namespace Kitar\Dynamodb\Model;

use Exception;
use Illuminate\Database\Eloquent\Model as BaseModel;

class Model extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * The Partition Key.
     * @var string
     */
    protected $primaryKey;

    /**
     * The Sort Key.
     * @var string|null
     */
    protected $sortKey;

    /**
     * The default value of the Sort Key.
     * @var string|null
     */
    protected $sortKeyDefault;

    /**
     * @inheritdoc
     */
    public function __construct(array $attributes = [])
    {
        $this->incrementing = false;

        if ($this->sortKey && $this->sortKeyDefault && ! isset($attributes[$this->sortKey])) {
            $this[$this->sortKey] = $this->sortKeyDefault;
        }

        parent::__construct($attributes);
    }

    /**
     * Get the key of the current item.
     *
     * @return array
     */
    public function getKey()
    {
        $key = [];

        $key[$this->primaryKey] = $this->getAttribute($this->primaryKey);

        if ($this->sortKey) {
            $key[$this->sortKey] = $this->getAttribute($this->sortKey);
        }

        return $key;
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return \Kitar\Dynamodb\Query\Builder
     */
    public function newQuery()
    {
        return $this->getConnection()->table($this->table)->usingModel(static::class);
    }

    /**
     * Save the model to the database.
     *
     * @param  array  $options
     * @return bool
     */
    public function save(array $options = [])
    {
        $query = $this->newQuery();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            $saved = $this->isDirty() ?
                        $this->performUpdate($query) : true;
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert($query);
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

    /**
     * Perform a model update operation.
     *
     * @param  \Kitar\Dynamodb\Query\Builder  $query
     * @return bool
     */
    protected function performUpdate($query)
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $this->newQuery()->key($this->getKey())->updateItem($dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Perform a model insert operation.
     *
     * @param  \Kitar\Dynamodb\Query\Builder  $query
     * @return bool
     */
    protected function performInsert($query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // First we'll need to create a fresh query instance and touch the creation and
        // update timestamps on this model, which are maintained by us for developer
        // convenience. After, we will just continue saving these model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $attributes = $this->getAttributes();

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        if (empty($attributes)) {
            return true;
        }

        // Prevent overwrites of an existing item.
        foreach (array_keys($this->getKey()) as $keyName) {
            $query = $query->condition($keyName, 'attribute_not_exists');
        }

        $query->putItem($attributes);

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Delete the model from the database.
     *
     * @return bool|null
     *
     * @throws \Exception
     */
    public function delete()
    {
        foreach ($this->getKey() as $keyName => $keyValue) {
            if (empty($keyValue)) {
                throw new Exception("{$keyName} must be defined but missing.");
            }
        }

        // If the model doesn't exist, there is nothing to delete so we'll just return
        // immediately and not do anything else. Otherwise, we will continue with a
        // deletion process on the model, firing the proper events, and so forth.
        if (! $this->exists) {
            return;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->newQuery()->deleteItem($this->getKey());

        $this->exists = false;

        // Once the model has been deleted, we will fire off the deleted event so that
        // the developers may hook into post-delete operations. We will then return
        // a boolean true as the delete is presumably successful on the database.
        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function __call($method, $parameters)
    {
        $allowedBuilderMethods = [
            "index",
            "key",
            "exclusiveStartKey",
            "consistentRead",
            "dryRun",
            "getItem",
            "putItem",
            "deleteItem",
            "updateItem",
            "scan",
            "filter",
            "filterIn",
            "filterBetween",
            "condition",
            "conditionIn",
            "conditionBetween",
            "keyCondition",
            "keyConditionIn",
            "keyConditionBetween",
        ];

        if (! in_array($method, $allowedBuilderMethods)) {
            static::throwBadMethodCallException($method);
        }

        return $this->forwardCallTo($this->newQuery(), $method, $parameters);
    }
}