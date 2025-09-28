<?php

namespace Survos\CoreBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Survos\StateBundle\Traits\EasyMarkingTrait;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

abstract class BaseCrudController extends AbstractCrudController
{

    public function xxconfigureActions(Actions $actions): Actions
    {
        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::DELETE)
            // Add the 'DETAIL' action on the index page
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ;
    }


    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, 'ROLE_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_ADMIN')
            ->setPermission(Action::BATCH_DELETE, 'ROLE_ADMIN');
//            ->remove(Crud::PAGE_INDEX, Action::NEW)
//            ->remove(Crud::PAGE_INDEX, Action::DELETE)
//            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            //->add(Crud::PAGE_INDEX, Action::DETAIL);
    }



    public function configureFilters(Filters $filters): Filters
    {
        if (property_exists($this, 'workflow') && $this->workflow) {
            $places = $this->workflow->getDefinition()->getPlaces();
            return $filters
                ->add(ChoiceFilter::new('marking')
                    ->setChoices($this->getPlaces())
                );
        } else {
            return $filters;
        }
    }

    public function configureFields(string $pageName): iterable
    {

        foreach (parent::configureFields($pageName) as $field) {
            $field = match ($field->getAsDto()->getProperty()) {
                'label' => null,
                'marking' => null,
                default => $field
            };
            if ($field) {
                yield $field;
            }
        }
    }
}
