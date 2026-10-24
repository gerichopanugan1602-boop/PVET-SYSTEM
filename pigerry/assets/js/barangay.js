/* Dependent dropdown: a <select data-depends="municipality" data-groups='{"0":[...]}'>
   only lists the options that belong to the value chosen in the parent field. */
(function () {
  'use strict';

  function init(select) {
    var parent = document.getElementById('f_' + select.getAttribute('data-depends'));
    if (!parent) return;

    var groups = {};
    try { groups = JSON.parse(select.getAttribute('data-groups') || '{}'); } catch (e) { groups = {}; }

    function fill(keep) {
      var list = groups[parent.value] || [];
      select.innerHTML = '';

      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = parent.value === '' ? 'Select a municipality first' : 'Select a barangay';
      select.appendChild(placeholder);

      list.forEach(function (name) {
        var o = document.createElement('option');
        o.value = name;
        o.textContent = name;
        if (name === keep) o.selected = true;
        select.appendChild(o);
      });
    }

    fill(select.value);

    parent.addEventListener('change', function () {
      fill('');
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  document.querySelectorAll('select[data-depends]').forEach(init);
})();