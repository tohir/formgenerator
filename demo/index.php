<?php

require_once 'common.php';

?><!DOCTYPE html>

<html>
<head>
    <title>Demo of Form Generator class</title>
    <script type="text/javascript" src="//code.jquery.com/jquery-1.11.3.min.js"></script>
    <script type="text/javascript" src="//cdn.jsdelivr.net/jquery.validation/1.14.0/jquery.validate.min.js"></script>
    <script type="text/javascript" src="//cdn.jsdelivr.net/jquery.validation/1.14.0/additional-methods.min.js"></script>
    <style type="text/css">
        label.error { color: red; font-weight: bold;}
    </style>
</head>

<body>
<?php

    $form = new FormGenerator($formArray);
    echo $form->display();

?>
</body>
</html>
