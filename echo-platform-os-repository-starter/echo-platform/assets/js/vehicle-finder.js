(() => {
  'use strict';

  const cfg = window.EchoVehicleFinder || {};
  if (!cfg.ajaxUrl || !cfg.nonce) return;

  const storageKeys = {
    garage: 'echoGarageVehicles',
    active: 'echoActiveVehicle'
  };

  const jsonStorage = {
    get(key, fallback) {
      try {
        const value = window.localStorage.getItem(key);
        return value ? JSON.parse(value) : fallback;
      } catch (error) {
        return fallback;
      }
    },
    set(key, value) {
      try {
        window.localStorage.setItem(key, JSON.stringify(value));
      } catch (error) {}
    },
    remove(key) {
      try {
        window.localStorage.removeItem(key);
      } catch (error) {}
    }
  };

  const request = async (action, params = {}, method = 'GET') => {
    const data = new URLSearchParams({ action, nonce: cfg.nonce, ...params });
    const options = { credentials: 'same-origin' };
    let url = cfg.ajaxUrl;

    if (method === 'POST') {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
      options.body = data.toString();
    } else {
      url += `${url.includes('?') ? '&' : '?'}${data.toString()}`;
    }

    const response = await fetch(url, options);
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || !payload.success) {
      throw new Error(payload?.data?.message || cfg.strings?.error || 'Request failed.');
    }
    return payload.data;
  };

  const setCookie = (name, value, days = 365) => {
    const expires = new Date(Date.now() + days * 86400000).toUTCString();
    document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
  };

  const clearCookie = (name) => {
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; path=/; SameSite=Lax`;
  };

  const text = (value) => String(value ?? '').trim();

  const labelForVehicle = (vehicle) => `${vehicle.year || ''} ${vehicle.make || ''} ${vehicle.model || ''}`.trim();

  const vehicleKey = (vehicle) => String(vehicle?.id || `${vehicle?.source || ''}:${vehicle?.source_vehicle_id || ''}`);

  const sameVehicle = (vehicle, vehicleId) => Boolean(vehicle && vehicleKey(vehicle) === String(vehicleId || ''));

  const normalizeGarage = (garage) => {
    const map = new Map();
    (Array.isArray(garage) ? garage : []).forEach((vehicle) => {
      const key = vehicleKey(vehicle);
      if (key && key !== ':') map.set(key, vehicle);
    });
    return Array.from(map.values()).slice(0, 10);
  };

  const getGarage = () => {
    if (cfg.isLoggedIn) return normalizeGarage(cfg.accountGarage);
    return normalizeGarage(jsonStorage.get(storageKeys.garage, []));
  };

  const setGarage = (garage) => {
    const next = normalizeGarage(garage);
    cfg.accountGarage = next;
    jsonStorage.set(storageKeys.garage, next);
    return next;
  };

  const garageContains = (garage, vehicle) => {
    const key = vehicleKey(vehicle);
    return Boolean(key && key !== ':' && normalizeGarage(garage).some((item) => vehicleKey(item) === key));
  };

  const getActiveVehicle = () => {
    if (cfg.isLoggedIn) return cfg.activeVehicle || null;
    return jsonStorage.get(storageKeys.active, null);
  };

  const setActiveVehicle = (vehicle) => {
    cfg.activeVehicle = vehicle || null;
    if (vehicle) {
      jsonStorage.set(storageKeys.active, vehicle);
      if (vehicle.id) setCookie('echo_active_vehicle_id', vehicle.id);
    } else {
      jsonStorage.remove(storageKeys.active);
      clearCookie('echo_active_vehicle_id');
    }
  };

  const renderAllGarageSummaries = (active) => {
    document.querySelectorAll('[data-echo-garage-summary]').forEach((container) => renderGarageSummary(container, active));
  };

  const clearVehicleQuery = () => {
    const url = new URL(window.location.href);
    let changed = false;
    ['echo_vehicle_id', 'year', 'make', 'model', 'enginetrim', 'option'].forEach((parameter) => {
      if (url.searchParams.has(parameter)) {
        url.searchParams.delete(parameter);
        changed = true;
      }
    });
    if (changed) window.history.replaceState({}, '', url.toString());
  };

  const clearActiveVehicle = () => {
    setActiveVehicle(null);
    clearVehicleQuery();
    renderAllGarageSummaries(null);
    document.dispatchEvent(new CustomEvent('echoVehicleCleared'));
  };

  const removeVehicleFromLocalGarage = (vehicle) => {
    if (!vehicle) return getGarage();
    return setGarage(getGarage().filter((item) => vehicleKey(item) !== vehicleKey(vehicle)));
  };

  const saveLocalVehicle = (vehicle, serverGarage = null) => {
    const garage = Array.isArray(serverGarage) ? serverGarage : getGarage();
    const key = vehicleKey(vehicle);
    const next = [vehicle, ...garage.filter((item) => vehicleKey(item) !== key)].slice(0, 10);
    setGarage(next);
    setActiveVehicle(vehicle);
    renderAllGarageSummaries(vehicle);
    return next;
  };

  const createSelect = (input, field, placeholder) => {
    if (!input) return null;
    if (input.tagName === 'SELECT') {
      input.dataset.echoField = field;
      return input;
    }
    const select = document.createElement('select');
    select.name = input.name;
    select.className = input.className;
    select.dataset.echoField = field;
    select.setAttribute('aria-label', placeholder);
    select.innerHTML = `<option value="">${placeholder}</option>`;
    input.replaceWith(select);
    return select;
  };

  const fillSelect = (select, items, placeholder, disabled = false) => {
    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.append(first);
    (items || []).forEach((item) => {
      const option = document.createElement('option');
      option.value = text(item.value);
      option.textContent = text(item.text);
      select.append(option);
    });
    select.disabled = disabled || !items?.length;
  };

  const enhanceForm = (form) => {
    if (form.dataset.echoEnhanced === '1') return;
    form.dataset.echoEnhanced = '1';
    form.classList.add('echo-vehicle-form');
    form.setAttribute('novalidate', 'novalidate');

    // The homepage theme previously rewrote the finder action to /YEAR/ and
    // produced URLs such as /2018/?make=Ford. Keep the form pinned to the
    // canonical WooCommerce shop and remove the legacy filter flag.
    form.method = 'get';
    form.action = cfg.shopUrl;
    form.querySelectorAll('[name="fitment_filter"]').forEach((field) => field.remove());

    const year = createSelect(form.querySelector('[name="year"]'), 'year', cfg.strings?.selectYear || 'Select year');
    const make = createSelect(form.querySelector('[name="make"]'), 'make', cfg.strings?.selectMake || 'Select make');
    const model = createSelect(form.querySelector('[name="model"]'), 'model', cfg.strings?.selectModel || 'Select model');
    const option = createSelect(form.querySelector('[name="enginetrim"], [name="option"]'), 'option', cfg.strings?.selectOption || 'Select engine / option');
    if (!year || !make || !model || !option) return;

    make.disabled = true;
    model.disabled = true;
    option.disabled = true;

    let internal = form.querySelector('[data-echo-internal-id]');
    if (!internal) {
      internal = document.createElement('input');
      internal.type = 'hidden';
      internal.name = 'echo_vehicle_id';
      internal.dataset.echoInternalId = '';
      form.append(internal);
    }

    let status = form.querySelector('[data-echo-status], .fitment-note');
    if (!status) {
      status = document.createElement('p');
      status.className = 'fitment-note';
      form.append(status);
    }
    status.dataset.echoStatus = '';
    status.setAttribute('aria-live', 'polite');

    const submit = form.querySelector('[type="submit"]');
    if (submit) {
      submit.dataset.echoShowParts = '';
      submit.disabled = true;
    }

    const universalLink = form.querySelector('a[href*="em_fitment=universal"]');
    if (universalLink) universalLink.classList.add('echo-shop-universal');

    let save = form.querySelector('[data-echo-save]');
    if (!save) {
      save = document.createElement('button');
      save.type = 'button';
      save.className = 'btn btn-secondary echo-save-vehicle';
      save.textContent = 'Save to My Garage';
      save.dataset.echoSave = '';
      const universal = form.querySelector('a[href*="em_fitment=universal"]');
      if (universal) universal.before(save);
      else form.append(save);
    }
    save.disabled = true;

    const wrapper = form.closest('[data-echo-vehicle-finder], .finder-stage-form, .echo-vehicle-finder') || form.parentElement;
    let garageSummary = wrapper?.querySelector('[data-echo-garage-summary]');
    if (!garageSummary && wrapper) {
      garageSummary = document.createElement('div');
      garageSummary.className = 'echo-garage-summary';
      garageSummary.dataset.echoGarageSummary = '';
      form.before(garageSummary);
    }

    let selectedDetails = null;

    const setStatus = (message, state = '') => {
      status.textContent = message;
      status.dataset.state = state;
    };

    // Garage Lookup v2 fallback tools. Government menus are helpful but can be
    // incomplete or temporarily unavailable, so customers can also decode a VIN
    // or enter a vehicle manually without getting stuck.
    let alternate = wrapper?.querySelector('[data-echo-alternate-lookup]');
    if (!alternate && wrapper) {
      alternate = document.createElement('section');
      alternate.className = 'echo-alternate-lookup';
      alternate.dataset.echoAlternateLookup = '';
      alternate.innerHTML = `
        <button type="button" class="echo-alt-toggle" data-echo-alt-toggle aria-expanded="false">Can't find your vehicle? Use VIN or manual entry</button>
        <div class="echo-alt-panel" data-echo-alt-panel hidden>
          <div class="echo-alt-tabs" role="tablist" aria-label="Other vehicle lookup methods">
            <button type="button" class="is-active" data-echo-alt-tab="vin">VIN lookup</button>
            <button type="button" data-echo-alt-tab="manual">Manual entry</button>
          </div>
          <div class="echo-alt-view is-active" data-echo-alt-view="vin">
            <label><span>17-character VIN</span><input type="text" maxlength="17" autocomplete="off" data-echo-vin placeholder="Example: 1FADP3TH9GL123456"></label>
            <button type="button" class="btn btn-secondary" data-echo-vin-submit>Decode VIN & Save Vehicle</button>
          </div>
          <div class="echo-alt-view" data-echo-alt-view="manual" hidden>
            <div class="echo-manual-grid">
              <label><span>Year *</span><input type="number" min="1900" max="${new Date().getFullYear() + 2}" data-echo-manual-year placeholder="2016"></label>
              <label><span>Make *</span><input type="text" data-echo-manual-make placeholder="Ford"></label>
              <label><span>Model *</span><input type="text" data-echo-manual-model placeholder="Focus RS"></label>
              <label><span>Engine / Trim</span><input type="text" data-echo-manual-engine placeholder="2.3L Turbo AWD"></label>
            </div>
            <button type="button" class="btn btn-secondary" data-echo-manual-submit>Save Vehicle & Shop Parts</button>
          </div>
          <p class="echo-alt-note">Manual vehicles still show universal products. Exact fitment badges appear only where supplier fitment has been verified.</p>
        </div>`;
      form.after(alternate);

      const toggle = alternate.querySelector('[data-echo-alt-toggle]');
      const panel = alternate.querySelector('[data-echo-alt-panel]');
      toggle?.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;
      });

      alternate.querySelectorAll('[data-echo-alt-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
          alternate.querySelectorAll('[data-echo-alt-tab]').forEach((item) => item.classList.toggle('is-active', item === tab));
          alternate.querySelectorAll('[data-echo-alt-view]').forEach((view) => {
            const activeView = view.dataset.echoAltView === tab.dataset.echoAltTab;
            view.classList.toggle('is-active', activeView);
            view.hidden = !activeView;
          });
        });
      });

      const openVehicleResults = (vehicle) => {
        if (!vehicle?.id) throw new Error('The vehicle could not be saved.');
        saveLocalVehicle(vehicle);
        const destination = new URL(cfg.shopUrl, window.location.origin);
        destination.searchParams.set('echo_vehicle_id', vehicle.id);
        window.location.assign(destination.toString());
      };

      alternate.querySelector('[data-echo-vin-submit]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const vinInput = alternate.querySelector('[data-echo-vin]');
        const vin = text(vinInput?.value).toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g, '');
        if (vin.length !== 17) {
          setStatus('Enter a complete 17-character VIN.', 'error');
          vinInput?.focus();
          return;
        }
        button.disabled = true;
        setStatus('Decoding VIN and saving vehicle…', 'loading');
        try {
          const data = await request('echo_save_vehicle', { source: 'nhtsa_vin', source_vehicle_id: 'vin', vin }, 'POST');
          openVehicleResults(data.vehicle);
        } catch (error) {
          setStatus(error.message, 'error');
          button.disabled = false;
        }
      });

      alternate.querySelector('[data-echo-manual-submit]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const yearValue = text(alternate.querySelector('[data-echo-manual-year]')?.value);
        const makeValue = text(alternate.querySelector('[data-echo-manual-make]')?.value);
        const modelValue = text(alternate.querySelector('[data-echo-manual-model]')?.value);
        const engineValue = text(alternate.querySelector('[data-echo-manual-engine]')?.value);
        if (!yearValue || !makeValue || !modelValue) {
          setStatus('Year, make, and model are required.', 'error');
          return;
        }
        button.disabled = true;
        setStatus('Saving vehicle and opening compatible products…', 'loading');
        try {
          const data = await request('echo_save_vehicle', {
            source: 'manual',
            source_vehicle_id: 'manual',
            year: yearValue,
            make: makeValue,
            model: modelValue,
            engine: engineValue,
            trim: engineValue
          }, 'POST');
          openVehicleResults(data.vehicle);
        } catch (error) {
          setStatus(error.message, 'error');
          button.disabled = false;
        }
      });
    }

    const setBusy = (select, busy) => {
      if (select) select.setAttribute('aria-busy', busy ? 'true' : 'false');
      form.classList.toggle('is-loading', busy);
    };

    const loadMenu = async (level, params, target, placeholder) => {
      setBusy(target, true);
      fillSelect(target, [], cfg.strings?.loading || 'Loading…', true);
      try {
        const data = await request('echo_vehicle_menu', { level, ...params });
        fillSelect(target, data.items || [], placeholder, false);
        setStatus('Vehicle list loaded from FuelEconomy.gov.', 'ready');
      } catch (error) {
        fillSelect(target, [], placeholder, true);
        setStatus(error.message, 'error');
      } finally {
        setBusy(target, false);
      }
    };

    const resetAfter = (field) => {
      const order = [year, make, model, option];
      const index = order.indexOf(field);
      order.slice(index + 1).forEach((select, offset) => {
        const placeholders = [cfg.strings?.selectMake, cfg.strings?.selectModel, cfg.strings?.selectOption];
        fillSelect(select, [], placeholders[index + offset] || 'Select', true);
      });
      selectedDetails = null;
      internal.value = '';
      save.disabled = true;
      if (submit) submit.disabled = true;
    };

    const resetFinderSelection = () => {
      selectedDetails = null;
      internal.value = '';
      year.value = '';
      fillSelect(make, [], cfg.strings?.selectMake || 'Select make', true);
      fillSelect(model, [], cfg.strings?.selectModel || 'Select model', true);
      fillSelect(option, [], cfg.strings?.selectOption || 'Select engine / option', true);
      save.disabled = true;
      if (submit) submit.disabled = true;
      setStatus('Choose a vehicle to see verified compatible parts.', 'ready');
    };

    document.addEventListener('echoVehicleCleared', resetFinderSelection);

    year.addEventListener('change', () => {
      resetAfter(year);
      if (year.value) loadMenu('make', { year: year.value }, make, cfg.strings?.selectMake || 'Select make');
    });

    make.addEventListener('change', () => {
      resetAfter(make);
      if (make.value) loadMenu('model', { year: year.value, make: make.value }, model, cfg.strings?.selectModel || 'Select model');
    });

    model.addEventListener('change', () => {
      resetAfter(model);
      if (model.value) loadMenu('options', { year: year.value, make: make.value, model: model.value }, option, cfg.strings?.selectOption || 'Select engine / option');
    });

    option.addEventListener('change', async () => {
      selectedDetails = null;
      internal.value = '';
      save.disabled = true;
      if (submit) submit.disabled = true;
      if (!option.value) return;

      setBusy(option, true);
      setStatus('Confirming the selected vehicle…', 'loading');
      try {
        const data = await request('echo_vehicle_details', { vehicle_id: option.value });
        selectedDetails = data.vehicle;
        save.disabled = false;
        if (submit) submit.disabled = false;
        setStatus(`${labelForVehicle(selectedDetails)} selected. Product results remain limited to verified fitment.`, 'ready');
      } catch (error) {
        setStatus(error.message, 'error');
      } finally {
        setBusy(option, false);
      }
    });

    const persistSelected = async () => {
      if (!selectedDetails) throw new Error('Choose a complete vehicle first.');
      const data = await request('echo_save_vehicle', {
        source: selectedDetails.source || 'epa',
        source_vehicle_id: selectedDetails.source_vehicle_id
      }, 'POST');
      selectedDetails = data.vehicle;
      internal.value = selectedDetails.id || '';
      saveLocalVehicle(selectedDetails, data.garage);
      renderGarageSummary(garageSummary, selectedDetails);
      return selectedDetails;
    };

    save.addEventListener('click', async () => {
      save.disabled = true;
      setStatus('Saving vehicle…', 'loading');
      try {
        const vehicle = await persistSelected();
        setStatus(`${labelForVehicle(vehicle)} saved to My Garage.`, 'success');
      } catch (error) {
        setStatus(error.message, 'error');
      } finally {
        save.disabled = false;
      }
    });

    let submitInFlight = false;

    const openVerifiedMatches = async (event = null) => {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      }
      if (submitInFlight) return;
      if (!selectedDetails) {
        setStatus('Choose year, make, model, and engine / option first.', 'error');
        return;
      }

      submitInFlight = true;
      if (submit) submit.disabled = true;
      setStatus('Opening verified matches…', 'loading');

      try {
        const vehicle = internal.value ? selectedDetails : await persistSelected();
        if (!vehicle?.id) throw new Error('The selected vehicle could not be saved.');

        // Never trust form.action here. The legacy homepage script changes it
        // to the selected year, which creates a non-existent /2018/ page.
        const destination = new URL(cfg.shopUrl, window.location.origin);
        destination.search = '';
        destination.hash = '';
        destination.searchParams.set('echo_vehicle_id', vehicle.id);
        window.location.assign(destination.toString());
      } catch (error) {
        submitInFlight = false;
        setStatus(error.message, 'error');
        if (submit) submit.disabled = false;
      }
    };

    // Capture phase wins before old theme handlers can rewrite the route or
    // manually submit the Year/Make/Model fields to a /YEAR/ URL.
    form.addEventListener('submit', openVerifiedMatches, true);
    form.addEventListener('click', (event) => {
      const trigger = event.target.closest('[type="submit"]');
      if (!trigger || !form.contains(trigger)) return;
      openVerifiedMatches(event);
    }, true);

    loadMenu('year', {}, year, cfg.strings?.selectYear || 'Select year');

    const active = getActiveVehicle();
    renderGarageSummary(garageSummary, active);
  };

  const renderGarageSummary = (container, active) => {
    if (!container) return;
    if (!active) {
      container.hidden = true;
      container.innerHTML = '';
      return;
    }
    const detail = active.option_label || active.engine || '';
    container.hidden = false;
    container.innerHTML = `
      <span class="echo-garage-kicker">Current Garage Vehicle</span>
      <strong>${escapeHtml(labelForVehicle(active))}</strong>
      ${detail ? `<small>${escapeHtml(detail)}</small>` : ''}
      <div class="echo-garage-summary-actions">
        <a href="${escapeAttribute(`${cfg.shopUrl}?echo_vehicle_id=${encodeURIComponent(active.id || '')}`)}">Shop verified matches →</a>
        <button type="button" data-echo-clear-current>${cfg.isLoggedIn ? 'Remove vehicle' : 'Clear vehicle'}</button>
      </div>
    `;
  };

  const escapeHtml = (value) => {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
  };

  const escapeAttribute = (value) => escapeHtml(value).replace(/`/g, '&#96;');

  const bindCurrentVehicleClear = () => {
    document.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-echo-clear-current]');
      if (!button) return;

      event.preventDefault();
      const active = getActiveVehicle();
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');

      try {
        const data = await request('echo_clear_active_vehicle', {
          remove_from_garage: cfg.isLoggedIn ? '1' : '0'
        }, 'POST');

        if (cfg.isLoggedIn) setGarage(Array.isArray(data.garage) ? data.garage : []);
        else removeVehicleFromLocalGarage(active);

        clearActiveVehicle();
      } catch (error) {
        // Always clear the browser state so a stale card cannot trap the user.
        if (!cfg.isLoggedIn) removeVehicleFromLocalGarage(active);
        clearActiveVehicle();
        window.alert(error.message);
      } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
      }
    });
  };

  const bindAccountGarage = () => {
    document.addEventListener('click', async (event) => {
      const activate = event.target.closest('[data-echo-activate]');
      const remove = event.target.closest('[data-echo-remove]');
      if (!activate && !remove) return;
      const card = event.target.closest('[data-vehicle-id]');
      const vehicleId = card?.dataset.vehicleId;
      if (!vehicleId) return;

      event.preventDefault();
      const trigger = activate || remove;
      trigger.disabled = true;
      try {
        if (activate) {
          const data = await request('echo_set_active_vehicle', { vehicle_id: vehicleId }, 'POST');
          saveLocalVehicle(data.vehicle);
          document.querySelectorAll('[data-vehicle-id].is-active').forEach((item) => item.classList.remove('is-active'));
          card.classList.add('is-active');
          trigger.disabled = false;
        } else {
          const data = await request('echo_remove_vehicle', { vehicle_id: vehicleId }, 'POST');
          const garage = setGarage(Array.isArray(data.garage)
            ? data.garage
            : getGarage().filter((item) => !sameVehicle(item, vehicleId)));

          if (data.activeVehicle) setActiveVehicle(data.activeVehicle);
          else clearActiveVehicle();
          renderAllGarageSummaries(data.activeVehicle || null);
          card.remove();

          const list = document.querySelector('[data-echo-account-garage] .echo-garage-list');
          if (list && garage.length === 0) {
            list.replaceWith(Object.assign(document.createElement('p'), {
              className: 'echo-garage-empty',
              textContent: 'No vehicles saved yet. Use the vehicle finder to add one.'
            }));
          }
        }
      } catch (error) {
        window.alert(error.message);
        trigger.disabled = false;
      }
    });
  };

  const syncInitialState = () => {
    if (cfg.isLoggedIn) {
      // Logged-in state must come from the uncached AJAX endpoint. The localized
      // page payload may have been generated before a garage removal.
      setGarage(Array.isArray(cfg.accountGarage) ? cfg.accountGarage : []);
      cfg.activeVehicle = null;
      jsonStorage.remove(storageKeys.active);
      clearCookie('echo_active_vehicle_id');
      renderAllGarageSummaries(null);
      return;
    }

    const localActive = jsonStorage.get(storageKeys.active, null);
    cfg.activeVehicle = localActive || null;
  };

  let stateRequest = null;
  const syncServerState = async () => {
    if (stateRequest) return stateRequest;

    stateRequest = request('echo_get_garage_state')
      .then((data) => {
        cfg.isLoggedIn = Boolean(data.isLoggedIn);

        if (cfg.isLoggedIn) {
          const garage = setGarage(Array.isArray(data.garage) ? data.garage : []);
          const active = data.activeVehicle && garageContains(garage, data.activeVehicle)
            ? data.activeVehicle
            : null;

          if (active) setActiveVehicle(active);
          else clearActiveVehicle();
          renderAllGarageSummaries(active);
          return;
        }

        const localActive = jsonStorage.get(storageKeys.active, null);
        const active = localActive || data.activeVehicle || null;
        if (active) setActiveVehicle(active);
        else clearActiveVehicle();
        renderAllGarageSummaries(active);
      })
      .catch(() => {
        if (cfg.isLoggedIn) {
          clearActiveVehicle();
          return;
        }
        renderAllGarageSummaries(getActiveVehicle());
      })
      .finally(() => {
        stateRequest = null;
      });

    return stateRequest;
  };

  const syncFromLocalStorage = () => {
    if (cfg.isLoggedIn) {
      syncServerState();
      return;
    }

    const stored = jsonStorage.get(storageKeys.active, null);
    cfg.activeVehicle = stored || null;
    renderAllGarageSummaries(stored);
    if (!stored) document.dispatchEvent(new CustomEvent('echoVehicleCleared'));
  };

  const init = () => {
    syncInitialState();
    document.querySelectorAll('form.em-static-finder, [data-echo-vehicle-finder] form').forEach(enhanceForm);
    if (!cfg.isLoggedIn) renderAllGarageSummaries(getActiveVehicle());
    bindCurrentVehicleClear();
    bindAccountGarage();
    syncServerState();
  };

  window.addEventListener('storage', (event) => {
    if (event.key === storageKeys.active || event.key === storageKeys.garage) {
      if (cfg.isLoggedIn) syncServerState();
      else syncFromLocalStorage();
    }
  });

  window.addEventListener('pageshow', () => {
    syncServerState();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') syncServerState();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
