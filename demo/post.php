<?php

require_once 'common.php';

$form = new FormGenerator($formArray, '', TRUE);

if ($form->isValid()) {
    echo '<pre>';
    var_dump($_POST);
    
    
} else {

?><!DOCTYPE html>

<html>
<head>
    <title>Page Title</title>
    <script type="text/javascript" src="//code.jquery.com/jquery-1.11.3.min.js"></script>
    <script type="text/javascript" src="//cdn.jsdelivr.net/jquery.validation/1.14.0/jquery.validate.min.js"></script>
    <script type="text/javascript" src="//cdn.jsdelivr.net/jquery.validation/1.14.0/additional-methods.min.js"></script>
    <style type="text/css">
        label.error { color: red; font-weight: bold;}
    </style>
</head>

<body>
<?php

    echo $form->display();

?>
</body>
</html>
<?php } ?>