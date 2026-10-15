<?php

/*
 | The buyer code on a delivery is a short numeric code checked in the street, so
 | it is guessable by anyone who can see the customer's jug. Reporting progress
 | is deliberately tight: ten attempts a minute is enough for a rider retrying
 | after a typo and far too slow to search the code space.
 */
return ['login' => 5, 'api' => 120, 'forecast' => 3, 'delivery' => 10];
