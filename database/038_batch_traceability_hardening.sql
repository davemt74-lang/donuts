CREATE TRIGGER IF NOT EXISTS trg_production_batches_quantity_insert
BEFORE INSERT ON production_batches
FOR EACH ROW
WHEN NEW.quantity_remaining > NEW.quantity_produced
BEGIN
  SELECT RAISE(ABORT,'quantity_remaining cannot exceed quantity_produced');
END;

CREATE TRIGGER IF NOT EXISTS trg_production_batches_quantity_update
BEFORE UPDATE OF quantity_remaining,quantity_produced ON production_batches
FOR EACH ROW
WHEN NEW.quantity_remaining > NEW.quantity_produced
BEGIN
  SELECT RAISE(ABORT,'quantity_remaining cannot exceed quantity_produced');
END;
