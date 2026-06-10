package client

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/authz"
	"github.com/mosamlife/wpmgr/apps/api/internal/domain"
)

func init() { gin.SetMode(gin.TestMode) }

// handlerStubSvc is a minimal stub of the client.Service for handler tests.
type handlerStubSvc struct {
	listFn   func(ctx context.Context, tenantID uuid.UUID, includeArchived bool) ([]Client, error)
	getFn    func(ctx context.Context, tenantID, id uuid.UUID) (Client, error)
	createFn func(ctx context.Context, in CreateInput) (Client, error)
	updateFn func(ctx context.Context, in UpdateInput) (Client, error)
	deleteFn func(ctx context.Context, tenantID, id uuid.UUID) error
	assignFn func(ctx context.Context, in AssignInput) (AssignResult, error)
}

func (s *handlerStubSvc) List(ctx context.Context, tenantID uuid.UUID, includeArchived bool) ([]Client, error) {
	if s.listFn != nil {
		return s.listFn(ctx, tenantID, includeArchived)
	}
	return nil, nil
}
func (s *handlerStubSvc) Get(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	if s.getFn != nil {
		return s.getFn(ctx, tenantID, id)
	}
	return Client{}, domain.NotFound("client_not_found", "not found")
}
func (s *handlerStubSvc) Create(ctx context.Context, in CreateInput) (Client, error) {
	if s.createFn != nil {
		return s.createFn(ctx, in)
	}
	return Client{ID: uuid.New(), TenantID: in.TenantID, Name: in.Name, CreatedAt: time.Now(), UpdatedAt: time.Now()}, nil
}
func (s *handlerStubSvc) Update(ctx context.Context, in UpdateInput) (Client, error) {
	if s.updateFn != nil {
		return s.updateFn(ctx, in)
	}
	return Client{ID: in.ID, TenantID: in.TenantID, UpdatedAt: time.Now()}, nil
}
func (s *handlerStubSvc) Archive(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	return Client{}, nil
}
func (s *handlerStubSvc) Delete(ctx context.Context, tenantID, id uuid.UUID) error {
	if s.deleteFn != nil {
		return s.deleteFn(ctx, tenantID, id)
	}
	return nil
}
func (s *handlerStubSvc) CountSites(ctx context.Context, tenantID, clientID uuid.UUID) (int64, error) {
	return 0, nil
}
func (s *handlerStubSvc) AssignSites(ctx context.Context, in AssignInput) (AssignResult, error) {
	if s.assignFn != nil {
		return s.assignFn(ctx, in)
	}
	return AssignResult{Updated: int64(len(in.SiteIDs))}, nil
}

// handlerSvcIface mirrors the subset of *Service used by Handler, allowing the
// stub above to satisfy the handler. Since Handler holds *Service directly, we
// need a thin adapter layer for tests.
//
// Rather than changing the production type, the test wires the handler via a
// service constructed around the stub repo — exploiting the fact that the
// service delegates straight to the repo with no additional logic in the
// methods tested here. For full isolation we use a stub repo.
func newHandlerTestEngine(stub *handlerStubSvc) (*gin.Engine, *handlerStubSvc) {
	// Build a service around a stub repo whose methods forward to stub.
	delegateRepo := &delegatingRepo{stub: stub}
	svc := NewService(delegateRepo)
	h := NewHandler(svc, nil)

	r := gin.New()
	r.Use(func(c *gin.Context) {
		// Inject an owner-scoped org principal that satisfies all client perms.
		p := domain.Principal{
			Type:     domain.PrincipalUser,
			UserID:   uuid.New(),
			TenantID: uuid.New(),
			Scope:    domain.ScopeOrg,
			Role:     string(authz.RoleOwner),
		}
		c.Request = c.Request.WithContext(domain.WithPrincipal(c.Request.Context(), p))
		c.Next()
	})
	v1 := r.Group("/api/v1")
	h.Register(v1)
	return r, stub
}

// delegatingRepo implements Repo by forwarding to the handlerStubSvc.
type delegatingRepo struct {
	stub *handlerStubSvc
}

func (d *delegatingRepo) List(ctx context.Context, tenantID uuid.UUID, includeArchived bool) ([]Client, error) {
	return d.stub.List(ctx, tenantID, includeArchived)
}
func (d *delegatingRepo) Get(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	return d.stub.Get(ctx, tenantID, id)
}
func (d *delegatingRepo) Create(ctx context.Context, in CreateInput) (Client, error) {
	return d.stub.Create(ctx, in)
}
func (d *delegatingRepo) Update(ctx context.Context, in UpdateInput) (Client, error) {
	return d.stub.Update(ctx, in)
}
func (d *delegatingRepo) Archive(ctx context.Context, tenantID, id uuid.UUID) (Client, error) {
	return d.stub.Archive(ctx, tenantID, id)
}
func (d *delegatingRepo) Delete(ctx context.Context, tenantID, id uuid.UUID) (int64, error) {
	if d.stub.deleteFn != nil {
		err := d.stub.deleteFn(ctx, tenantID, id)
		if err != nil {
			return 0, err
		}
		return 1, nil
	}
	return 1, nil
}
func (d *delegatingRepo) CountSites(ctx context.Context, tenantID, clientID uuid.UUID) (int64, error) {
	return d.stub.CountSites(ctx, tenantID, clientID)
}
func (d *delegatingRepo) AssignSites(ctx context.Context, in AssignInput) (int64, error) {
	res, err := d.stub.AssignSites(ctx, in)
	return res.Updated, err
}

// ---------------------------------------------------------------------------
// Route-contract tests
// ---------------------------------------------------------------------------

func TestListClients_OK(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{
		listFn: func(_ context.Context, _ uuid.UUID, _ bool) ([]Client, error) {
			return []Client{{ID: uuid.New(), Name: "Acme", CreatedAt: time.Now(), UpdatedAt: time.Now()}}, nil
		},
	})
	req := httptest.NewRequest(http.MethodGet, "/api/v1/clients", nil)
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestCreateClient_ValidBody_Returns201(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	body := bytes.NewBufferString(`{"name":"New Client"}`)
	req := httptest.NewRequest(http.MethodPost, "/api/v1/clients", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestCreateClient_EmptyName_Returns422(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	// An empty string name passes JSON parsing but fails service validation.
	body := bytes.NewBufferString(`{"name":"   "}`)
	req := httptest.NewRequest(http.MethodPost, "/api/v1/clients", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestGetClient_InvalidUUID_Returns422(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	req := httptest.NewRequest(http.MethodGet, "/api/v1/clients/not-a-uuid", nil)
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	// domain.Validation maps to HTTP 422 (Unprocessable Entity).
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestGetClient_NotFound_Returns404(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{
		getFn: func(_ context.Context, _, _ uuid.UUID) (Client, error) {
			return Client{}, domain.NotFound("client_not_found", "client not found")
		},
	})
	req := httptest.NewRequest(http.MethodGet, "/api/v1/clients/"+uuid.New().String(), nil)
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusNotFound {
		t.Fatalf("expected 404, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestDeleteClient_OK_Returns204(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	req := httptest.NewRequest(http.MethodDelete, "/api/v1/clients/"+uuid.New().String(), nil)
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusNoContent {
		t.Fatalf("expected 204, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestAssignSites_EmptySiteIDs_Returns422(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	body := bytes.NewBufferString(`{"site_ids":[]}`)
	req := httptest.NewRequest(http.MethodPut, "/api/v1/clients/assignments", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestAssignSites_InvalidSiteUUID_Returns422(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	body := bytes.NewBufferString(`{"site_ids":["not-a-uuid"]}`)
	req := httptest.NewRequest(http.MethodPut, "/api/v1/clients/assignments", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	// domain.Validation maps to HTTP 422 (Unprocessable Entity).
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d body=%s", w.Code, w.Body.String())
	}
}

func TestAssignSites_ValidUnassign_Returns200(t *testing.T) {
	r, _ := newHandlerTestEngine(&handlerStubSvc{})
	siteID := uuid.New().String()
	body := bytes.NewBufferString(`{"site_ids":["` + siteID + `"]}`)
	req := httptest.NewRequest(http.MethodPut, "/api/v1/clients/assignments", body)
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body=%s", w.Code, w.Body.String())
	}
}
