import { Pipe, PipeTransform } from '@angular/core';
import { PosterSource, posterSrc } from '../core/util/poster';

/** {{ movie | poster }}: a plakát képe, hiányában a cím alapján készült alapplakát. Programoknál (előadás) a `poster:true` jelzi a típust. */
@Pipe({ name: 'poster', standalone: true })
export class PosterPipe implements PipeTransform {
  transform(item: PosterSource | null | undefined, theater?: boolean): string {
    return posterSrc(item && theater !== undefined ? { ...item, is_theater: theater } : item);
  }
}
