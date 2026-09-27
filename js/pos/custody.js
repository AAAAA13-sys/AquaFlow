// AquaFlow CASHIER terminal - container custody intake + shape selection
// Logic copied from the original AquaFlow prototype, extended with the
// container shape selector and cross-page intake persistence.

// Reads the intake inputs of the current page; missing inputs count as 0 so the
// same functions can run on every CASHIER/ step page.
function readIntake() {
  const v = id => { const el = document.getElementById(id); return el ? (+el.value || 0) : 0; };
  return { oS: v('outS'), iS: v('inS'), oR: v('outR'), iR: v('inR') };
}

// Restore already-typed intake counts when the cashier returns to this step.
function restoreIntakeInputs() {
  const t = POS.intake || {};
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || 0; };
  set('outS', t.oS);
  set('inS', t.iS);
  set('outR', t.oR);
  set('inR', t.iR);
  const dmg = document.getElementById('dmg');
  if (dmg) dmg.checked = !!POS.dmg;
}

// 1. Select Container Shape (Figure 4 flowchart step)
function applyShapeUI() {
  const slim = document.getElementById('shapeSlim');
  const round = document.getElementById('shapeRound');
  if (slim) slim.className = 'btn btn-sm ' + (POS.shape === 'S' ? 'btn-primary' : 'btn-ghost');
  if (round) round.className = 'btn btn-sm ' + (POS.shape === 'R' ? 'btn-primary' : 'btn-ghost');
}

function selectShape(s) {
  POS.shape = s;
  applyShapeUI();
  const target = document.getElementById(s === 'S' ? 'outS' : 'outR');
  if (target) target.focus();
  savePOSState();
}

function custodyCheck() {
  // Only the intake step has these inputs; on other steps keep the saved counts.
  if (document.getElementById('outS')) POS.intake = readIntake();
  const t = POS.intake || { oS: 0, iS: 0, oR: 0, iR: 0 };
  const dS = t.oS - t.iS, dR = t.oR - t.iR;
  const el = document.getElementById('custWarn');
  if (el) {
    if (dS <= 0 && dR <= 0) {
      el.className = 'custody-warning-message pill-ok';
      el.textContent = 'All bottles accounted for.';
    } else {
      el.className = 'custody-warning-message pill-warn';
      let msg = 'Missing bottles: ';
      if (dS > 0) msg += dS + ' slim bottle(s) not returned ';
      if (dR > 0) msg += dR + ' round bottle(s) not returned ';
      msg += '- billed to ' + posCust().name + '.';
      el.textContent = msg;
    }
  }
  savePOSState();
  if (typeof renderPOS === 'function') renderPOS();
  return { dS, dR };
}
