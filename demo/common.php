<?php

require_once '../vendor/autoload.php';

$fruitOptions = array(
    array('label'=>'Orange', 'value'=>'orange'),
    array('label'=>'Apple', 'value'=>'apple'),
    array('label'=>'Banana', 'value'=>'banana'),
    array('label'=>'Pear', 'value'=>'pear'),
);

$formArray = array (
            'name' => 'testForm',
            'method' => 'post',
            'action' => 'post.php',
            'submitbutton' => 'Submit this Form',
            'elements' => array (
                'textinput' => array(
                    'type' => 'text',
                    'label' => 'Text',
                    'validation' => array(
                        'required' => TRUE,
                        'minlength' => 3,
                        'maxlength' => 15,
                    ),
                ),
                'regexdemo' => array(
                    'type' => 'text',
                    'label' => 'Regex Demo',
                    'validation' => array(
                        'required' => TRUE,
                        'regex' => array (
                            'jsRegex' => '^ABCD_\d+$',
                            'phpRegex' => '/\AABCD_\d+\Z/',
                        )
                    ),
                ),
                'email' => array(
                    'type' => 'email',
                    'label' => 'Email',
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'date' => array(
                    'type' => 'date',
                    'label' => 'Date',
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'url' => array(
                    'type' => 'url',
                    'label' => 'URL',
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'number' => array(
                    'type' => 'number',
                    'label' => 'Number',
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'password1' => array(
                    'type' => 'password',
                    'label' => 'Password',
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'password2' => array(
                    'type' => 'password',
                    'label' => 'Repeat Password',
                    'validation' => array(
                        'required' => TRUE,
                        'equalTo' => 'password1'
                    ),
                ),
                'fruit1' => array(
                    'type' => 'dropdown',
                    'label' => 'Fruit 1',
                    'options' => $fruitOptions,
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'fruit2' => array(
                    'type' => 'radiobuttongroup',
                    'label' => 'Fruit 2',
                    'options' => $fruitOptions,
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'fruit3' => array(
                    'type' => 'checkboxgroup',
                    'label' => 'Fruit 3',
                    'options' => $fruitOptions,
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'fruit4' => array(
                    'type' => 'multiselect',
                    'label' => 'Fruit 4',
                    'options' => $fruitOptions,
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
                'fruit5' => array(
                    'type' => 'list',
                    'label' => 'Fruit 5',
                    'options' => $fruitOptions,
                    'validation' => array(
                        'required' => TRUE,
                    ),
                ),
            )
        );