ALTER TABLE borrowers
  DROP INDEX idx_borrowers_employee_id,
  ADD UNIQUE KEY uq_borrowers_employee_id (employee_id);
