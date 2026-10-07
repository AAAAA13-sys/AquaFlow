{{--
  Inline term tip.

  @param string $text   The explanation shown in the popover.
  @param string $label  Accessible name for the trigger, e.g. "What is ROP?".
                        Screen readers announce this instead of a bare "?".

  Usage:  <th>Reorder Below @include('partials.tip', ['text' => '…', 'label' => 'What is ROP?'])</th>

  The trigger is a real <button> rather than a CSS :hover tooltip, so it works
  with a mouse, a finger, the keyboard, and closes on Escape. JS lives in
  public/js/data/store.js.
--}}
<span class="af-tip" data-tip="{{ $text }}">
  <button type="button" class="af-tip-btn" aria-expanded="false"
          aria-label="{{ $label ?? 'Show explanation' }}">?</button>
</span>