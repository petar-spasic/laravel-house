<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

/** The card changed since the caller read it (its rev no longer matches). */
class Changed extends Conflict {}
