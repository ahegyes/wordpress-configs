<?php

const TOP_CONST_A = 'value';
const TOP_CONST_B = 42;

define( 'DEFINED_CONST_A', 'value' );
define( 'DEFINED_CONST_B', 42 );

// Non-define function calls — must NOT be picked up as constants.
strlen( 'NOT_A_CONSTANT' );
some_other_function( 'NEITHER_IS_THIS' );
