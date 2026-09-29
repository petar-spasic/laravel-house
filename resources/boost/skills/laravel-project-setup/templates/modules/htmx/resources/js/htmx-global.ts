// htmx extensions self-register against window.htmx; imports hoist, so this runs before them.
import htmx from 'htmx.org';

window.htmx = htmx;
