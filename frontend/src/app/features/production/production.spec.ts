import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { DynamicDialogConfig, DynamicDialogRef } from 'primeng/dynamicdialog';
import { of } from 'rxjs';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { Confirm } from '../../ui/confirm';
import { Dialogs } from '../../ui/dialog';
import { ReceivedLotDialog } from './material-detail';
import { ConsumptionsDialog, OfDetailPage, ProducedLotDialog } from './of-detail';
import { InvoiceDialog } from './order-detail';
import { ConsumedMaterial, MaterialBatch, ProductionLists, statusTone } from './production-data';

const batch = (over: Partial<MaterialBatch>): MaterialBatch => ({
  ulid: 'B1', batch_number: 'L1', supplier: { ulid: 'SA', code: 'A', name: 'Supplier A' }, supplier_batch_reference: null, received_on: '2026-08-01', quantity: '100.000', consumed: '0.000',
  over_consumed: false, delivery_note: null, invoice_reference: null, external_reference: null, import_source: null, notes: null, unit_price: '10.0000', total_amount: '1000.000', delivery_cost: null,
  currency_code: 'TND', tax_rate_pct: null, unit_cost: '10.0000', purchase_value: '1000.000', consumed_value: '0.000', ...over,
});
const material = { ulid: 'M1', reference: 'DEN', name: 'Denim', unit: 'kg', supplier: { ulid: 'SA', code: 'A', name: 'Supplier A' } };

describe('production: received lots, consumption, produced lots, invoices', () => {
  let api: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn>; put: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn> };
  let ref: { close: ReturnType<typeof vi.fn> };
  let money: boolean;
  const config: { data: unknown } = { data: null };

  // The classes are exercised without their templates: what is under test is what they propose and what they send.
  const create = <T>(type: new () => T, data: unknown): T => {
    // One holder for the data of the dialog under test: several dialogs can be opened in one test.
    config.data = data;

    return TestBed.runInInjectionContext(() => new type());
  };
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const inner = (instance: unknown): any => instance;

  beforeEach(() => {
    money = true;
    api = { get: vi.fn(() => of({})), post: vi.fn(() => of({})), put: vi.fn(() => of({})), delete: vi.fn(() => of({})) };
    ref = { close: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideZonelessChangeDetection(),
        { provide: Api, useValue: api }, { provide: DynamicDialogRef, useValue: ref }, { provide: DynamicDialogConfig, useValue: config },
        { provide: Toast, useValue: { success: vi.fn(), error: vi.fn(), applyValidation: () => false } },
        { provide: TranslocoService, useValue: { translate: (key: string) => key } },
        { provide: Session, useValue: { can: (permission: string) => permission !== 'production.finance.view' || money, company: () => ({ currency_code: 'TND' }), sitesFor: () => [{ ulid: 'S1', name: 'Nabeul' }] } },
        { provide: ProductionLists, useValue: { suppliers: () => of([]), steps: () => of([]), materials: () => of([{ ...material, batches: [batch({}), batch({ ulid: 'B2', batch_number: 'L2' })] }]) } },
        { provide: Dialogs, useValue: { open: vi.fn() } }, { provide: Confirm, useValue: { ask: vi.fn(async () => true) } },
      ],
    });
  });

  it('proposes the supplier of the material for a new received lot, and the lot’s own supplier when editing', async () => {
    const fresh = inner(create(ReceivedLotDialog, { material, batch: null }));
    expect(fresh.form.controls.supplier.value).toBe('SA');

    // The lot was delivered by another supplier than the one the material proposes today: it keeps showing its own.
    const existing = inner(create(ReceivedLotDialog, { material: { ...material, supplier: { ulid: 'SB', code: 'B', name: 'Supplier B' } }, batch: batch({}) }));
    expect(existing.form.controls.supplier.value).toBe('SA');

    fresh.form.patchValue({ batch_number: 'L9', quantity: '50,5', unit_price: '12', currency_code: 'eur' });
    await fresh.submit();
    expect(api.post).toHaveBeenCalledWith('production/materials/M1/batches', expect.objectContaining({ batch_number: 'L9', supplier: 'SA', quantity: '50.5', unit_price: '12', total_amount: null, currency_code: 'EUR' }));

    await existing.submit();
    expect(api.put.mock.calls[0][0]).toBe('production/batches/B1');
  });

  it('refuses a received lot without a number and an amount that is not a number', async () => {
    const dialog = inner(create(ReceivedLotDialog, { material, batch: null }));
    await dialog.submit();
    expect(api.post).not.toHaveBeenCalled();
    dialog.form.patchValue({ batch_number: 'L9', unit_price: 'abc' });
    await dialog.submit();
    expect(api.post).not.toHaveBeenCalled();
  });

  it('keeps one consumption line per received lot and sends "lot non précisé" as no lot', async () => {
    const order = { ulid: 'O1', order_number: 'OF-1', consumptions: [
      { material: 'M1', reference: 'DEN', name: 'Denim', unit: 'kg', batch: 'B1', batch_number: 'L1', quantity: '12.000', step: 'cutting', site: 'S1', consumed_on: '2026-09-02', notes: null },
      { material: 'M1', reference: 'DEN', name: 'Denim', unit: 'kg', batch: null, batch_number: null, quantity: '3.000', step: null, site: null, consumed_on: null, notes: null },
    ] };
    const dialog = inner(create(ConsumptionsDialog, order));
    expect(dialog.lines.length).toBe(2);
    expect(dialog.batchesOf('M1').map((item: { batch_number: string }) => item.batch_number)).toEqual(['L1', 'L2']);

    // A second lot of the same material is a second line.
    dialog.add();
    dialog.lines.at(2).patchValue({ material: 'M1', batch: 'B2', quantity: '8' });
    await dialog.submit();
    expect(api.put).toHaveBeenCalledWith('production/manufacturing-orders/O1/consumptions', { consumptions: [
      { material: 'M1', batch: 'B1', quantity: '12', step: 'cutting', site: 'S1', consumed_on: '2026-09-02', notes: null },
      { material: 'M1', batch: null, quantity: '3', step: null, site: null, consumed_on: null, notes: null },
      { material: 'M1', batch: 'B2', quantity: '8', step: null, site: null, consumed_on: null, notes: null },
    ] });

    // Changing the material of a line forgets its lot: a lot belongs to one material.
    dialog.lines.at(0).controls.material.setValue('M2');
    expect(dialog.lines.at(0).controls.batch.value).toBe('');
  });

  it('creates a produced lot on its OF, with no supplier anywhere', async () => {
    const order = { ulid: 'O1', order_number: 'OF-1', article: { reference: 'JEAN' }, client: { name: 'Levant' }, customer_order: { order_number: 'CMD-1' } };
    const dialog = inner(create(ProducedLotDialog, { of: order, lot: null }));
    expect(Object.keys(dialog.form.controls)).not.toContain('supplier');
    dialog.form.patchValue({ lot_number: 'LF-A', quantity: 400, produced_on: '2026-09-10' });
    await dialog.submit();

    const [path, body] = api.post.mock.calls[0];
    expect(path).toBe('production/manufacturing-orders/O1/produced-lots');
    expect(body).toMatchObject({ lot_number: 'LF-A', quantity: 400, status: 'produced', delivered_on: null, site: null });
    expect(body).not.toHaveProperty('supplier');
  });

  it('records an invoice for the whole order unless an OF is chosen', async () => {
    const order = { ulid: 'C1', order_number: 'CMD-1', orders: [{ ulid: 'O1', order_number: 'OF-1', article: 'JEAN' }] };
    const dialog = inner(create(InvoiceDialog, { order, invoice: null }));
    dialog.form.patchValue({ number: 'FV-1', amount: '2000,5' });
    await dialog.submit();
    expect(api.post).toHaveBeenCalledWith('production/orders/C1/invoices', expect.objectContaining({ kind: 'invoice', number: 'FV-1', amount: '2000.5', manufacturing_order: null }));
  });

  it('shows how far consumption is from the plan, and says why a gain cannot be given', () => {
    api.get.mockReturnValue(of(null));
    const page = inner(TestBed.runInInjectionContext(() => new OfDetailPage()));
    const row = (planned: string | null, consumed: string): ConsumedMaterial => ({ planned, consumed } as ConsumedMaterial);

    expect(page.share(row('20.000', '10.000'))).toBe(50);
    // Over the plan: the bar is full, the figures beside it say by how much.
    expect(page.share(row('20.000', '23.000'))).toBe(100);
    expect(page.share(row(null, '5.000'))).toBe(0);
    expect(page.reasons(['no_price', 'unknown_material_cost'])).toBe('production.missing.no_price · production.missing.unknown_material_cost');
    // Money is managed only by who may both manage production and see amounts.
    expect(page.canManageMoney).toBe(true);
    money = false;
    expect(inner(TestBed.runInInjectionContext(() => new OfDetailPage())).canManageMoney).toBe(false);
  });

  it('gives each status its colour', () => {
    expect([statusTone('completed'), statusTone('delivered'), statusTone('in_progress'), statusTone('produced'), statusTone('cancelled'), statusTone('planned')])
      .toEqual(['approved', 'approved', 'pending', 'pending', 'rejected', 'draft']);
  });
});
