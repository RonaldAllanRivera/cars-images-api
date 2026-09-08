import errorsFixture from '../__fixtures__/errors.json';
import healthFixture from '../__fixtures__/health.json';
import imageFixture from '../__fixtures__/image.json';
import imagesFixture from '../__fixtures__/images.json';
import loginFixture from '../__fixtures__/login.json';
import meFixture from '../__fixtures__/me.json';
import reviewFixture from '../__fixtures__/review.json';
import searchFixture from '../__fixtures__/search.json';
import searchesFixture from '../__fixtures__/searches.json';
import validationErrorFixture from '../__fixtures__/validation-error.json';
import {
  cursorPage,
  ErrorEventSchema,
  HealthSummarySchema,
  ImageSchema,
  LoginResponseSchema,
  SearchSchema,
  single,
  UserSchema,
  ValidationErrorSchema,
} from '../schemas';

describe('the API contract', () => {
  it('parses the unwrapped login response', () => {
    const parsed = LoginResponseSchema.parse(loginFixture);

    expect(parsed.token_type).toBe('Bearer');
    expect(parsed.abilities).toContain('review:write');
  });

  it('parses the data-wrapped single resources', () => {
    expect(single(UserSchema).parse(meFixture).data.email).toBeTruthy();
    expect(single(ImageSchema).parse(imageFixture).data.review_status).toBe('approved');
    expect(single(ImageSchema).parse(reviewFixture).data.review_status).toBe('rejected');
    expect(single(SearchSchema).parse(searchFixture).data.status).toBe('completed');
    expect(single(HealthSummarySchema).parse(healthFixture).data.errors_last_24h).toBeGreaterThanOrEqual(0);
  });

  it('parses the cursor-paginated collections', () => {
    const images = cursorPage(ImageSchema).parse(imagesFixture);

    expect(images.data.length).toBe(1);
    // per_page=1 with two images, so there is a further page.
    expect(images.meta.next_cursor).not.toBeNull();

    expect(cursorPage(SearchSchema).parse(searchesFixture).meta.next_cursor).toBeNull();
    expect(cursorPage(ErrorEventSchema).parse(errorsFixture).data[0]?.context).toBe('search_run');
  });

  it('parses a 422', () => {
    const parsed = ValidationErrorSchema.parse(validationErrorFixture);

    // The 30-year span breaks the cap, and the cap names the offending field.
    expect(parsed.errors.to_year).toBeDefined();
  });

  it('rejects a response with a field the schema does not know about being wrong', () => {
    expect(() => ImageSchema.parse({ ...imageFixture.data, review_status: 'maybe' })).toThrow();
  });
});
