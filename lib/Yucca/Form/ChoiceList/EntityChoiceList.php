<?php
/*
 * This file was delivered to you as part of the Yucca package.
 *
 * (c) Rémi JANOT <r.janot@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Yucca\Form\ChoiceList;

use Symfony\Component\Form\Exception\RuntimeException;
use Symfony\Component\Form\Exception\StringCastException;
use Symfony\Component\Form\Extension\Core\ChoiceList\ObjectChoiceList;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Yucca\Component\EntityManager;
use Yucca\Component\Iterator\Iterator;


/**
 * Unique Entity Validator checks if one or a set of fields contain unique values.
 * Inspired by Doctrine Bridge bundle
 */
class EntityChoiceList extends ObjectChoiceList
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var string
     */
    private $class;

    /**
     * @var \Doctrine\Common\Persistence\Mapping\ClassMetadata
     */
    private $classMetadata;

    /**
     * Contains the query builder that builds the query for fetching the
     * entities
     *
     * This property should only be accessed through queryBuilder.
     *
     * @var Iterator
     */
    private $iterator;

    /**
     * The identifier field, if the identifier is not composite
     *
     * @var array
     */
    private $idField = null;

    /**
     * Whether to use the identifier for index generation
     *
     * @var Boolean
     */
    private $idAsIndex = false;

    /**
     * Whether to use the identifier for value generation
     *
     * @var Boolean
     */
    private $idAsValue = false;

    /**
     * Whether the entities have already been loaded.
     *
     * @var Boolean
     */
    private $loaded = false;

    /**
     * The preferred entities.
     *
     * @var array
     */
    private $preferredEntities = array();

    /**
     * Creates a new entity choice list.
     *
     * @param EntityManager             $manager           An EntityManager instance
     * @param string                    $modelClassName   The model class name
     * @param string                    $selectorClassName The selector class name
     * @param string                    $labelPath         The property path used for the label
     * @param Iterator                  $iterator          An optional query builder
     * @param array                     $entities          An array of choices
     * @param array                     $preferredEntities An array of preferred choices
     * @param string                    $groupPath         A property path pointing to the property used
     *                                                     to group the choices. Only allowed if
     *                                                     the choices are given as flat array.
     * @param PropertyAccessorInterface $propertyAccessor The reflection graph for reading property paths.
     */
    public function __construct(EntityManager $manager, $modelClassName, $selectorClassName, $labelPath = null, Iterator $iterator = null, $entities = null,  array $preferredEntities = array(), $groupPath = null, PropertyAccessorInterface $propertyAccessor = null)
    {
        $this->entityManager = $manager;
        $this->modelClassName = $modelClassName;
        $this->selectorClassName = $selectorClassName;
        $this->iterator = $iterator;
        $this->loaded = is_array($entities) || $entities instanceof \Traversable;
        $this->preferredEntities = $preferredEntities;

        if (!$this->loaded) {
            // Make sure the constraints of the parent constructor are
            // fulfilled
            $entities = array();
        }

        parent::__construct($entities, $labelPath, $preferredEntities, $groupPath, null, $propertyAccessor);
    }

    /**
     * Returns the list of entities
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getChoices()
    {
        if (!$this->loaded) {
            $this->load();
        }

        return parent::getChoices();
    }

    /**
     * Returns the values for the entities
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getValues()
    {
        if (!$this->loaded) {
            $this->load();
        }

        return parent::getValues();
    }

    /**
     * Returns the choice views of the preferred choices as nested array with
     * the choice groups as top-level keys.
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getPreferredViews()
    {
        if (!$this->loaded) {
            $this->load();
        }

        return parent::getPreferredViews();
    }

    /**
     * Returns the choice views of the choices that are not preferred as nested
     * array with the choice groups as top-level keys.
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getRemainingViews()
    {
        if (!$this->loaded) {
            $this->load();
        }

        return parent::getRemainingViews();
    }

    /**
     * Returns the entities corresponding to the given values.
     *
     * @param array $values
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getChoicesForValues(array $values)
    {
        if (!$this->loaded) {
            $this->load();
        }

        return parent::getChoicesForValues($values);
    }

    /**
     * Returns the values corresponding to the given entities.
     *
     * @param array $entities
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getValuesForChoices(array $entities)
    {
        if (!$this->loaded) {
            $this->load();
        }

        $choices = $this->fixChoices($entities);
        $allValues = $this->getValues();
        $values = array();

        foreach ($choices as $i => $givenChoice) {
            foreach ($this->getChoices() as $j => $choice) {
                if ($choice && $givenChoice && $choice->getId() == $givenChoice->getId()) {
                    $values[$i] = $allValues[$j];
                    unset($choices[$i]);

                    if (0 === count($choices)) {
                        break 2;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Returns the indices corresponding to the given entities.
     *
     * @param array $entities
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getIndicesForChoices(array $entities)
    {
        if (!$this->loaded) {
            // Optimize performance for single-field identifiers. We already
            // know that the IDs are used as indices

            // Attention: This optimization does not check choices for existence
            if ($this->idAsIndex) {
                $indices = array();

                foreach ($entities as $entity) {
                    if ($entity instanceof $this->class) {
                        // Make sure to convert to the right format
                        $indices[] = $this->fixIndex(current($this->getIdentifierValues($entity)));
                    }
                }

                return $indices;
            }

            $this->load();
        }

        return parent::getIndicesForChoices($entities);
    }

    /**
     * Returns the entities corresponding to the given values.
     *
     * @param array $values
     *
     * @return array
     *
     * @see Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceListInterface
     */
    public function getIndicesForValues(array $values)
    {
        if (!$this->loaded) {
            // Optimize performance for single-field identifiers.

            // Attention: This optimization does not check values for existence
            if ($this->idAsIndex && $this->idAsValue) {
                return $this->fixIndices($values);
            }

            $this->load();
        }

        return parent::getIndicesForValues($values);
    }

    /**
     * Creates a new unique index for this entity.
     *
     * If the entity has a single-field identifier, this identifier is used.
     *
     * Otherwise a new integer is generated.
     *
     * @param mixed $entity The choice to create an index for
     *
     * @return integer|string A unique index containing only ASCII letters,
     *                        digits and underscores.
     */
    protected function createIndex($entity)
    {
        if ($this->idAsIndex) {
            return $this->fixIndex(current($this->getIdentifierValues($entity)));
        }

        return parent::createIndex($entity);
    }

    /**
     * Creates a new unique value for this entity.
     *
     * If the entity has a single-field identifier, this identifier is used.
     *
     * Otherwise a new integer is generated.
     *
     * @param mixed $entity The choice to create a value for
     *
     * @return integer|string A unique value without character limitations.
     */
    protected function createValue($entity)
    {
        if ($this->idAsValue) {
            return (string) current($this->getIdentifierValues($entity));
        }

        return parent::createValue($entity);
    }

    /**
     * Loads the list with entities.
     */
    private function load()
    {
        if ($this->iterator) {
            $entities = $this->iterator->getArray();
        } else {
            $selector = $this->entityManager->getSelectorManager()->getSelector($this->selectorClassName);
            $iterator = new Iterator(
                $selector,
                $this->entityManager,
                $this->modelClassName
            );
            $entities = $iterator->getArray();
        }

        try {
            // The second parameter $labels is ignored by ObjectChoiceList
            parent::initialize($entities, array(), $this->preferredEntities);
        } catch (StringCastException $e) {
            throw new StringCastException(str_replace('argument $labelPath', 'option "property"', $e->getMessage()), null, $e);
        }

        $this->loaded = true;
    }

    /**
     * Returns the values of the identifier fields of an entity.
     *
     * Doctrine must know about this entity, that is, the entity must already
     * be persisted or added to the identity map before. Otherwise an
     * exception is thrown.
     *
     * @param object $entity The entity for which to get the identifier
     *
     * @return array          The identifier values
     *
     * @throws RuntimeException If the entity does not exist in Doctrine's identity map
     */
    private function getIdentifierValues($entity)
    {
        return $entity->getId();
    }
}
