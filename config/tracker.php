<?php

return [
    // A category is flagged "not realistic" when the weekly rate needed to finish
    // is more than this multiple of the rate implied by its original plan.
    'unrealistic_rate_factor' => 1.5,

    // A topic can only be "Mastered" when an independent attempt on a linked question
    // scored at least this percentage.
    'mastery_pct' => 80,

    // A question counts as "needs review" when its latest attempt is below this
    // percentage and the subject has no threshold of its own.
    'review_below_pct' => 50,
];
