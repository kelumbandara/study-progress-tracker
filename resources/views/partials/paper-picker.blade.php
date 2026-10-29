{{-- $withQuestion, $subjects, $years, optional $pre (ExamQuestion with paper) --}}
<div id="paperPicker"
     data-with-question="{{ $withQuestion ? 1 : 0 }}"
     data-papers="{{ route('api.papers') }}" data-topics="{{ route('api.topics') }}" data-paper-base="{{ url('/api/papers') }}" data-store-base="{{ url('/papers') }}"
     data-store-paper="{{ route('papers.store') }}"
     @if (isset($pre) && $pre) data-pre-subject="{{ $pre->paper->subject_id }}" data-pre-year="{{ $pre->paper->exam_year }}" data-pre-paper="{{ $pre->paper_id }}" data-pre-question="{{ $pre->id }}" @endif>
    <div class="form-row">
        <div class="form-group col-12 col-md-4">
            <label for="pp_subject">{{ __('Subject') }}</label>
            <select id="pp_subject" class="form-control" required>
                <option value="">{{ __('Choose subject…') }}</option>
                @foreach ($subjects as $s)<option value="{{ $s->id }}">{{ __($s->name) }}</option>@endforeach
            </select>
        </div>
        <div class="form-group col-6 col-md-3">
            <label for="pp_year">{{ __('Exam year') }}</label>
            <select id="pp_year" class="form-control" required>
                <option value="">{{ __('Year…') }}</option>
                @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
            </select>
        </div>
        <div class="form-group col-6 col-md-5">
            <label for="pp_paper">{{ __('Paper') }}</label>
            <select id="pp_paper" name="paper_id" class="form-control" required><option value="">{{ __('Choose paper…') }}</option></select>
        </div>
    </div>

    @if ($withQuestion)
        <div class="form-group">
            <label for="pp_question">{{ __('Question') }}</label>
            <select id="pp_question" name="question_id" class="form-control" required><option value="">{{ __('Choose question…') }}</option></select>
            <small id="pp_max" class="form-text text-muted"></small>
        </div>
    @endif

    <details class="mb-3">
        <summary class="text-primary" style="cursor:pointer">{{ __('Paper not listed? Register it here') }}</summary>
        <div class="border rounded p-2 mt-2">
            <div class="form-row">
                <div class="col-6 col-md-4 mb-1"><input id="ap_name" class="form-control form-control-sm" placeholder="{{ __('Paper name, e.g. Paper I') }}" maxlength="32"></div>
                <div class="col-6 col-md-3 mb-1"><input id="ap_max" type="number" min="1" class="form-control form-control-sm" placeholder="{{ __('Max marks (optional)') }}"></div>
                <div class="col-12 col-md-3 mb-1"><button type="button" id="ap_btn" class="btn btn-sm btn-outline-primary btn-block">{{ __('Register paper') }}</button></div>
            </div>
            <div id="ap_msg" class="small mt-1"></div>
        </div>
    </details>

    @if ($withQuestion)
        <details class="mb-3">
            <summary class="text-primary" style="cursor:pointer">{{ __('Question not listed? Register it here') }}</summary>
            <div class="border rounded p-2 mt-2">
                <div class="form-row">
                    <div class="col-6 col-md-3 mb-1"><input id="aq_no" class="form-control form-control-sm" placeholder="{{ __('Question no.') }}" maxlength="16"></div>
                    <div class="col-6 col-md-3 mb-1"><input id="aq_max" type="number" step="0.01" min="0.01" class="form-control form-control-sm" placeholder="{{ __('Max marks') }}"></div>
                    <div class="col-12 col-md-6 mb-1"><select id="aq_topics" multiple class="form-control" style="width:100%"></select></div>
                </div>
                <button type="button" id="aq_btn" class="btn btn-sm btn-outline-primary">{{ __('Register question') }}</button>
                <div id="aq_msg" class="small mt-1"></div>
            </div>
        </details>
    @endif
</div>
@php
$i18nKeys = [
    'Choose paper…',
    'Choose question…',
    'No paper registered — add it below',
    'No questions registered — add one below',
    'Not part of a full-paper attempt',
    'Part of:',
    'Maximum marks for this question:',
    'Choose subject and year, then enter a paper name (e.g. Paper I).',
    'Could not save the paper.',
    'That paper already existed and is now selected.',
    'Paper registered and selected.',
    'Search and pick one or more topics',
    'Choose a paper first.',
    'Enter the question number and maximum marks.',
    'Could not save the question.',
    'That question already existed and is now selected.',
    'Question registered and selected.',
];
@endphp
<script>window.I18N = {!! json_encode(collect($i18nKeys)->mapWithKeys(fn ($k) => [$k => __($k)]), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
